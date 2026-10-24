// tools/test_generate_js.mjs: tests for public/script/generate.js (the generator page).
//
// Run:  node tools/test_generate_js.mjs
//
// No browser and no dependencies: the script runs in a node:vm context with a small fake
// DOM (only the element ids generate.html really has), stubbed timers and a scripted
// fetch, so nothing is sent anywhere. tools/ is repo-only, don't upload it.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root   = fileURLToPath(new URL('..', import.meta.url));
const script = readFileSync(root + 'public/script/generate.js', 'utf8');
const html   = readFileSync(root + 'generate.html', 'utf8');
const ids    = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map(m => m[1]));

let passed = 0;
let failed = 0;

function check(name, ok, detail = '') {
    if (ok) {
        passed++;
        console.log(`  ok    ${name}`);
    } else {
        failed++;
        console.log(`  FAIL  ${name}${detail ? `\n        ${detail}` : ''}`);
    }
}

function same(name, expected, actual) {
    const e = JSON.stringify(expected);
    const a = JSON.stringify(actual);
    check(name, e === a, `expected ${e}, got ${a}`);
}

function element(id) {
    const classes = new Set();
    return {
        id, value: '', textContent: '', innerHTML: '', disabled: false, style: {},
        classList: { add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c) },
        focus() {}, addEventListener() {},
    };
}

/**
 * Load generate.js in a fresh context. replies: one { status, body } per expected request,
 * in order. Returns the page (its globals), its elements and the requests it sent.
 */
function loadPage(replies = []) {
    const elements = new Map();
    const requests = [];
    const document = {
        getElementById(id) {
            if (!ids.has(id)) throw new Error(`generate.js asked for #${id}, which generate.html does not have`);
            if (!elements.has(id)) elements.set(id, element(id));
            return elements.get(id);
        },
        addEventListener() {},
    };
    const fetch = async (url, options) => {
        requests.push({ url, method: options.method, headers: options.headers, body: JSON.parse(options.body) });
        const reply = replies.shift();
        if (!reply) throw new Error('unexpected request ' + options.body);
        return { ok: reply.status < 400, status: reply.status, json: async () => reply.body };
    };
    const context = vm.createContext({
        document, fetch, TextEncoder, console,
        setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {},
    });
    vm.runInContext(script, context, { filename: 'generate.js' });
    const page = new Proxy({}, { get: (_, name) => vm.runInContext(String(name), context) });
    return { page, el: id => document.getElementById(id), requests, left: () => replies.length };
}

// ─── 1. Page text renders as text (XSS) ───────────────────────────────────────

console.log('\n1. Result JSON: strings from the crawled pages render as text');

{
    const llmr = {
        v: '2.1', ts: 1761350400,
        s: { d: 'site.example.com', a: { n: '<svg onload=alert(3)>', r: '' } },
        p: [{
            id: 'x', u: '/"><img src=x onerror=alert(4)>',
            ti: '<img src=x onerror=alert(1)>',
            sum: '</span><script>alert(2)</script>',
            kw: ['<b>bold</b>', 'Tom &amp; Jerry', 'a > b < c'],
            wc: 12,
        }],
    };
    const { page, el } = loadPage();
    page.setState('done', { llmr, stats: { pages: 1, reduction_pct: 97.1, llmr_bytes: 400 } });
    const out = el('code').innerHTML;

    for (const tag of ['<img', '<script', '<svg', '<b>', '</b>']) {
        check(`no live ${tag} element in the result`, !out.includes(tag));
    }
    const markup = out.replace(/<span class="j[ksbn]">/g, '').replace(/<\/span>/g, '');
    check('only the highlighter\'s own <span> tags are markup', !/[<>]/.test(markup), markup.match(/.{0,20}[<>].{0,20}/)?.[0]);
    const text = markup.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    same('the visible text is exactly the JSON (so the title shows as typed)', JSON.stringify(llmr, null, 2), text);
    check('title shown escaped', out.includes('&lt;img src=x onerror=alert(1)&gt;'));
    check('an entity in page text stays literal (&amp;amp;)', out.includes('Tom &amp;amp; Jerry'));
    check('highlighting kept: key span', out.includes('<span class="jk">"ti":</span>'));
    check('highlighting kept: number span', out.includes('<span class="jn">12</span>'));
    same('copy/download text is the raw JSON', JSON.stringify(llmr, null, 2), page.lastJson);
    same('stats unit markup intact', '97.1<span class="stat-unit">%</span>', el('sRed').innerHTML);
}

// ─── 2. Requests: same-origin JSON ────────────────────────────────────────────

console.log('\n2. Requests: POST JSON to the same-origin endpoint');

{
    const { page, el, requests, left } = loadPage([
        { status: 200, body: { sitemap_url: 'https://site.example.com/sitemap.xml', pages_found: 3 } },
        { status: 200, body: { llmr: { v: '2.1', s: { d: 'site.example.com' }, p: [] }, stats: { pages: 0 } } },
    ]);
    el('urlInput').value = ' https://site.example.com ';
    await page.generate();
    same('generate: discovery then generation', [
        { url: 'https://site.example.com' },
        { url: 'https://site.example.com', sitemap_url: 'https://site.example.com/sitemap.xml' },
    ], requests.map(r => r.body));
    same('relative endpoint URL (same origin, no CORS needed)', ['config/generate.php', 'config/generate.php'], requests.map(r => r.url));
    same('POST with Content-Type: application/json', [['POST', 'application/json'], ['POST', 'application/json']],
         requests.map(r => [r.method, r.headers['Content-Type']]));
    same('generate: ends done', ['done', 0], [page.state, left()]);
}

{
    const { page, el, requests } = loadPage([
        { status: 200, body: { sitemap_url: 'https://site.example.com/sitemap.xml', pages_found: 0 } },
    ]);
    el('urlInput').value = 'https://site.example.com';
    await page.generate();
    same('generate, empty sitemap: no generation request', 1, requests.length);
    same('generate, empty sitemap: asks for another sitemap', ['sitemap_not_found', 'flex'], [page.state, el('sitemapRow').style.display]);
}

// ─── 3. Typed sitemap: counted first, generation only when it has pages ───────

console.log('\n3. Retry with a typed sitemap: Mode A first, so an empty one keeps the day\'s run');

{
    const { page, el, requests, left } = loadPage([
        { status: 200, body: { sitemap_url: 'https://site.example.com/empty.xml', pages_found: 0 } },
    ]);
    el('urlInput').value = 'https://site.example.com';
    el('sitemapInput').value = 'https://site.example.com/empty.xml';
    await page.retryWithSitemap();
    same('retry, empty sitemap: one discovery request with mode "discover"',
         [{ url: 'https://site.example.com', sitemap_url: 'https://site.example.com/empty.xml', mode: 'discover' }],
         requests.map(r => r.body));
    same('retry, empty sitemap: no generation, sitemap field shown again', ['sitemap_not_found', 'flex', 0],
         [page.state, el('sitemapRow').style.display, left()]);
    check('retry, empty sitemap: says no pages were found', el('error').textContent.includes('No pages on this site'), el('error').textContent);
}

{
    const { page, el, requests, left } = loadPage([
        { status: 200, body: { sitemap_url: 'https://site.example.com/posts.xml', pages_found: 40, pages_capped: true } },
        { status: 200, body: { llmr: { v: '2.1', s: { d: 'site.example.com' }, p: [] }, stats: { pages: 0 }, warning: 'capped_at_25' } },
    ]);
    el('urlInput').value = 'https://site.example.com';
    el('sitemapInput').value = 'https://site.example.com/posts.xml';
    await page.retryWithSitemap();
    same('retry, sitemap with pages: counted, then generated', [
        { url: 'https://site.example.com', sitemap_url: 'https://site.example.com/posts.xml', mode: 'discover' },
        { url: 'https://site.example.com', sitemap_url: 'https://site.example.com/posts.xml' },
    ], requests.map(r => r.body));
    same('retry, sitemap with pages: ends done', ['done', 0], [page.state, left()]);
}

{
    const { page, el, requests } = loadPage([
        { status: 400, body: { error: 'refused_url', field: 'sitemap_url', reason: 'off_origin' } },
    ]);
    el('urlInput').value = 'https://site.example.com';
    el('sitemapInput').value = 'https://other.example.net/sitemap.xml';
    await page.retryWithSitemap();
    same('retry, refused sitemap: no generation, field kept on screen', [1, 'sitemap_not_found', 'flex'],
         [requests.length, page.state, el('sitemapRow').style.display]);
}

// ─── 4. Error messages ────────────────────────────────────────────────────────

console.log('\n4. Error messages');

const message = async (status, body) => {
    const { page, el } = loadPage([{ status, body }]);
    el('urlInput').value = 'https://site.example.com';
    await page.generate();
    return el('error').textContent;
};

{
    const text = await message(400, { error: 'invalid_url' });
    check('invalid_url: plain-language message, not "Server error"', text.startsWith('Enter a full web address') && !text.includes('Server error'), text);
}
{
    const text = await message(400, { error: 'refused_url', field: 'url', reason: 'own_address' });
    same('own_address explained', "That URL can't be used: it points to the server that runs this generator.", text);
}
{
    const text = await message(400, { error: 'refused_url', field: 'url', reason: 'bad_url' });
    same('bad_url explained', "That URL can't be used: it is not a valid web address.", text);
}

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
