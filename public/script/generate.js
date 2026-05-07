/* generate.js — LLMR multi-page sitemap generator
 *
 * State machine:
 *   idle → discovering → fetching → summarizing → done
 *              └→ sitemap_not_found → (user enters URL) → fetching → …
 *   any → rate_limited | error
 */

let state          = 'idle';
let lastJson       = null;
let progressTimers = [];
let elapsedTimer   = null;
let elapsedStart   = 0;
let pendingWarning = ''; // survives fetching→summarizing fake-progress transitions

// ── Example quick-fill ────────────────────────────────────────────────────────

function setUrl(u) {
    document.getElementById('urlInput').value = u;
    document.getElementById('urlInput').focus();
}

// ── Status / error / warning helpers ─────────────────────────────────────────

function showStatus(msg) {
    document.getElementById('status').classList.add('on');
    document.getElementById('statusMsg').textContent = msg;
    document.getElementById('elapsed').textContent   = '';
}

function hideStatus() {
    document.getElementById('status').classList.remove('on');
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }
}

function showError(msg) {
    const el = document.getElementById('error');
    el.textContent = msg;
    el.classList.add('on');
}

function hideError() { document.getElementById('error').classList.remove('on'); }

function showWarning(msg) {
    const el = document.getElementById('warning');
    el.textContent = '⚠ ' + msg;
    el.classList.add('on');
}

function hideWarning() { document.getElementById('warning').classList.remove('on'); }

// ── State machine ─────────────────────────────────────────────────────────────

function setState(s, data = {}) {
    state = s;

    // Cancel all pending fake-progress timers
    progressTimers.forEach(clearTimeout);
    progressTimers = [];

    // Stop elapsed counter
    if (elapsedTimer) { clearInterval(elapsedTimer); elapsedTimer = null; }

    // Reset shared UI — warning is preserved across fake-progress states
    // (fetching → summarizing) so the cap notice stays visible during the wait.
    // Only clear it at genuine resets or terminal states.
    hideError();
    hideStatus();
    const isProgressTransition = (s === 'fetching' || s === 'summarizing');
    if (!isProgressTransition) {
        hideWarning();
        pendingWarning = '';
    }
    document.getElementById('sitemapRow').style.display = 'none';
    document.getElementById('result').classList.remove('on');
    document.getElementById('stats').classList.remove('on');

    const genBtn = document.getElementById('genBtn');

    switch (s) {

        case 'idle':
            genBtn.disabled = false;
            break;

        case 'discovering':
            genBtn.disabled = true;
            showStatus('Looking for sitemap…');
            // Fake progress: after 3 s transition to fetching (if still discovering)
            progressTimers.push(
                setTimeout(() => { if (state === 'discovering') setState('fetching', data); }, 3000)
            );
            break;

        case 'fetching': {
            genBtn.disabled = true;
            const n = data.pages_found ? ` ${data.pages_found}` : '';
            showStatus(`Fetching${n} pages…`);
            // Show cap warning upfront so user knows before the timer starts
            if (data.pages_found && data.pages_found > 25) {
                pendingWarning = `Found ${data.pages_found.toLocaleString()} pages — sampling 25 at random.`;
                showWarning(pendingWarning);
            }
            // After 8 s transition to summarizing
            progressTimers.push(
                setTimeout(() => { if (state === 'fetching') setState('summarizing', data); }, 8000)
            );
            break;
        }

        case 'summarizing': {
            genBtn.disabled = true;
            // Cap timer at 25 pages regardless of total pages found
            const cappedPages = data.pages_found ? Math.min(25, data.pages_found) : 25;
            const approxSecs  = Math.round(cappedPages * 1.5);
            showStatus(`Generating AI summaries… (this takes ~${approxSecs}s)`);
            // Restore cap warning if it was set during fetching
            if (pendingWarning) showWarning(pendingWarning);
            elapsedStart = Date.now();
            elapsedTimer = setInterval(() => {
                const secs = Math.round((Date.now() - elapsedStart) / 1000);
                document.getElementById('elapsed').textContent = ` · ${secs}s elapsed`;
            }, 1000);
            break;
        }

        case 'sitemap_not_found':
            genBtn.disabled = false;
            document.getElementById('sitemapRow').style.display = 'flex';
            showError('No sitemap found automatically. Enter your sitemap URL to continue:');
            break;

        case 'rate_limited': {
            genBtn.disabled = false;
            const next = data.next_available
                ? new Date(data.next_available).toLocaleString()
                : 'tomorrow';
            showError(`You've already generated today. Next available: ${next}`);
            break;
        }

        case 'done':
            genBtn.disabled = false;
            _populateResult(data);
            if (data.warning === 'capped_at_25') {
                showWarning('Result capped at 25 pages — random sample applied.');
            } else if (data.warning) {
                showWarning(data.warning);
            }
            if (data.warning_mistral) {
                // If a warning is already shown, append; otherwise show fresh
                const el = document.getElementById('warning');
                if (el.classList.contains('on')) {
                    el.textContent += ' · ' + data.warning_mistral;
                } else {
                    showWarning(data.warning_mistral);
                }
            }
            break;

        case 'error':
            genBtn.disabled = false;
            showError(data.message || 'Something went wrong. Please try again.');
            break;
    }
}

// ── Result population ─────────────────────────────────────────────────────────

function _populateResult(data) {
    const stats = data.stats || {};
    lastJson = JSON.stringify(data.llmr, null, 2);

    const pages = stats.pages || (data.llmr?.p?.length ?? 0);
    const red   = stats.reduction_pct ?? 0;
    const bytes = stats.llmr_bytes ?? new TextEncoder().encode(lastJson).length;

    document.getElementById('sPages').textContent = pages;
    document.getElementById('sRed').innerHTML     = red + '<span class="stat-unit">%</span>';
    document.getElementById('sSize').innerHTML    = (bytes / 1024).toFixed(1) + '<span class="stat-unit">KB</span>';
    document.getElementById('stats').classList.add('on');

    document.getElementById('rInfo').textContent = `${pages} page${pages !== 1 ? 's' : ''} · ${bytes.toLocaleString()} bytes`;
    document.getElementById('code').innerHTML    = highlight(lastJson);
    document.getElementById('result').classList.add('on');
}

// ── Request helpers ───────────────────────────────────────────────────────────

async function _post(payload) {
    const resp = await fetch('config/generate.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify(payload),
    });
    const data = await resp.json();
    return { resp, data };
}

function _handleError(data, resp) {
    if (data.error === 'sitemap_not_found') { setState('sitemap_not_found'); return true; }
    if (data.error === 'rate_limited')       { setState('rate_limited', data); return true; }
    if (data.error)                          { setState('error', { message: 'Server error: ' + data.error }); return true; }
    if (!resp.ok)                            { setState('error', { message: `HTTP ${resp.status}` }); return true; }
    return false;
}

// ── Entry points ──────────────────────────────────────────────────────────────

async function generate() {
    const url = document.getElementById('urlInput').value.trim();
    if (!url) return;

    setState('discovering');

    try {
        // Step 1 — discover sitemap
        const { resp, data } = await _post({ url });
        if (_handleError(data, resp)) return;

        // Discovery succeeded — data = { sitemap_url, pages_found }
        const sitemapUrl  = data.sitemap_url;
        const pagesFound  = data.pages_found || 0;

        setState('fetching', { pages_found: pagesFound });

        // Step 2 — full processing
        const { resp: resp2, data: data2 } = await _post({ url, sitemap_url: sitemapUrl });
        if (_handleError(data2, resp2)) return;

        setState('done', data2);

    } catch (e) {
        setState('error', { message: 'Network error: ' + e.message });
    }
}

async function retryWithSitemap() {
    const url        = document.getElementById('urlInput').value.trim();
    const sitemapUrl = document.getElementById('sitemapInput').value.trim();
    if (!url || !sitemapUrl) return;

    setState('fetching', {});

    try {
        const { resp, data } = await _post({ url, sitemap_url: sitemapUrl });
        if (_handleError(data, resp)) return;

        setState('done', data);

    } catch (e) {
        setState('error', { message: 'Network error: ' + e.message });
    }
}

// ── Copy / Download (unchanged) ───────────────────────────────────────────────

function copyJson() {
    if (!lastJson) return;
    navigator.clipboard.writeText(lastJson).then(() => {
        const el = document.getElementById('copyOk');
        el.classList.add('on');
        setTimeout(() => el.classList.remove('on'), 1500);
    });
}

function downloadJson() {
    if (!lastJson) return;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lastJson], { type: 'application/json' }));
    a.download = 'llmr.json';
    a.click();
}

// ── JSON highlight (unchanged) ────────────────────────────────────────────────

function highlight(json) {
    return json.replace(
        /("(\\u[\da-fA-F]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
        m => {
            if (/^"/.test(m)) return /:$/.test(m) ? `<span class="jk">${m}</span>` : `<span class="js">${m}</span>`;
            if (/true|false|null/.test(m)) return `<span class="jb">${m}</span>`;
            return `<span class="jn">${m}</span>`;
        }
    );
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('urlInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') generate();
    });
    document.getElementById('sitemapInput').addEventListener('keydown', e => {
        if (e.key === 'Enter') retryWithSitemap();
    });
});
