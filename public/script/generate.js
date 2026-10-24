/* generate.js — LLMR multi-page sitemap generator
 *
 * State machine:
 *   idle → discovering → fetching → summarizing → done
 *              └→ sitemap_not_found → (user enters URL) → discovering → fetching → …
 *   any → rate_limited | error
 *
 * Two requests per run: discovery (Mode A, 30 per hour) then generation (Mode B,
 * 1 per day). A sitemap URL typed by the user goes through Mode A too ("mode":
 * "discover"), and generation is skipped when discovery finds no pages, so an
 * empty sitemap never uses up the day's run.
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
            showStatus(data.message || 'Looking for sitemap…');
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
                const found = data.pages_found.toLocaleString() + (data.pages_capped ? '+' : '');
                pendingWarning = `Found ${found} pages. Sampling 25 at random.`;
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
            showError(data.message || 'No sitemap found automatically. Enter your sitemap URL to continue:');
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
                showWarning('Result capped at 25 pages: random sample applied.');
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
    document.getElementById('sRed').innerHTML     = Number(red) + '<span class="stat-unit">%</span>';
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

// Why the server refused a URL (refused_url.reason)
const REFUSAL_REASONS = {
    bad_url:         'it is not a valid web address.',
    bad_scheme:      'only http:// and https:// addresses are supported.',
    bad_port:        'only the standard web ports (80 and 443) are allowed.',
    bad_host:        'the host is not a public domain name or IP address.',
    credentials:     'addresses with a username or password are not allowed.',
    dns_failed:      'the domain does not resolve. Check the spelling.',
    private_address: 'it points to a private, local or reserved network address.',
    own_address:     'it points to the server that runs this generator.',
    off_origin:      'the sitemap must be on the same site as the URL above (www. and the bare domain both count).',
};

function _refusedMessage(data) {
    const what = data.field === 'sitemap_url' ? 'That sitemap URL' : 'That URL';
    return `${what} can't be used: ${REFUSAL_REASONS[data.reason] || 'it cannot be fetched.'}`;
}

function _handleError(data, resp) {
    if (data.error === 'sitemap_not_found') { setState('sitemap_not_found'); return true; }
    if (data.error === 'rate_limited')       { setState('rate_limited', data); return true; }
    if (data.error === 'invalid_url') {
        setState('error', { message: 'Enter a full web address, such as https://yourdomain.com.' });
        return true;
    }
    if (data.error === 'discovery_rate_limited') {
        const next = data.next_available ? new Date(data.next_available).toLocaleTimeString() : 'an hour';
        setState('error', { message: `Too many sitemap lookups from your network this hour. Try again after ${next}.` });
        return true;
    }
    if (data.error === 'refused_url' || data.error === 'invalid_sitemap_url') {
        const message = data.error === 'refused_url'
            ? _refusedMessage(data)
            : 'That sitemap URL is not a valid web address.';
        // A bad sitemap URL can be fixed in place, so keep the sitemap field on screen
        if (data.error === 'invalid_sitemap_url' || data.field === 'sitemap_url') {
            setState('sitemap_not_found', { message });
        } else {
            setState('error', { message });
        }
        return true;
    }
    if (data.error === 'sitemap_empty') {
        setState('error', { message: 'The sitemap lists no pages on this site.' });
        return true;
    }
    if (data.error === 'fetch_failed') {
        setState('error', { message: 'None of the sampled pages could be fetched as HTML.' });
        return true;
    }
    if (data.error === 'rate_limit_unavailable') {
        setState('error', { message: 'The generator is temporarily unavailable. Please try again later.' });
        return true;
    }
    if (data.error)                          { setState('error', { message: 'Server error: ' + data.error }); return true; }
    if (!resp.ok)                            { setState('error', { message: `HTTP ${resp.status}` }); return true; }
    return false;
}

// ── Entry points ──────────────────────────────────────────────────────────────

// Step 2, after a successful discovery: data = { sitemap_url, pages_found, pages_capped? }
async function _generateFrom(url, data) {
    const sitemapUrl  = data.sitemap_url;
    const pagesFound  = data.pages_found || 0;

    // Nothing to generate from: stop here rather than spend the day's generation
    if (!pagesFound) {
        setState('sitemap_not_found', {
            message: `No pages on this site were found in ${sitemapUrl}. Enter another sitemap URL:`,
        });
        return;
    }

    setState('fetching', { pages_found: pagesFound, pages_capped: data.pages_capped });

    const { resp, data: data2 } = await _post({ url, sitemap_url: sitemapUrl });
    if (_handleError(data2, resp)) return;

    setState('done', data2);
}

async function generate() {
    const url = document.getElementById('urlInput').value.trim();
    if (!url) return;

    setState('discovering');

    try {
        // Step 1 — discover sitemap
        const { resp, data } = await _post({ url });
        if (_handleError(data, resp)) return;

        await _generateFrom(url, data);

    } catch (e) {
        setState('error', { message: 'Network error: ' + e.message });
    }
}

async function retryWithSitemap() {
    const url        = document.getElementById('urlInput').value.trim();
    const sitemapUrl = document.getElementById('sitemapInput').value.trim();
    if (!url || !sitemapUrl) return;

    setState('discovering', { message: 'Reading sitemap…' });

    try {
        // Step 1 — count the given sitemap's pages (hourly limit, not the daily one)
        const { resp, data } = await _post({ url, sitemap_url: sitemapUrl, mode: 'discover' });
        if (_handleError(data, resp)) return;

        await _generateFrom(url, data);

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

// ── JSON highlight ────────────────────────────────────────────────────────────

// The JSON holds text taken from the crawled pages: escape it first so it renders as text
function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function highlight(json) {
    return escapeHtml(json).replace(
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
