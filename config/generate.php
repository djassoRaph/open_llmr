<?php
/**
 * config/generate.php — LLMR multi-page sitemap generator endpoint
 *
 * Mode A — Discovery (no sitemap_url in body):
 *   Discovers sitemap, returns { sitemap_url, pages_found } or { error }
 *
 * Mode B — Processing (sitemap_url provided):
 *   Rate-limits, fetches all pages in parallel, calls Mistral per-page,
 *   returns { llmr, stats, warning?, warning_mistral? }
 */

declare(strict_types=1);
set_time_limit(120);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// ─── Config ───────────────────────────────────────────────────────────────────

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2) + ['', ''];
        $_ENV[trim($key)] = trim($val, " \t\"'");
    }
}

loadEnv(__DIR__ . '/../.env');

$MISTRAL_KEY      = $_ENV['MISTRAL_API_KEY']  ?? '';
$MISTRAL_MODEL    = $_ENV['MISTRAL_MODEL']    ?? 'mistral-small-latest';
$MISTRAL_ENDPOINT = $_ENV['MISTRAL_ENDPOINT'] ?? 'https://api.mistral.ai/v1/chat/completions';
$MISTRAL_TIMEOUT  = (int) ($_ENV['MISTRAL_TIMEOUT'] ?? 20);

// ─── SSRF guard ───────────────────────────────────────────────────────────────

function isSafeUrl(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '') {
        return false;
    }
    $ip = gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// ─── Generic HTTP fetch ───────────────────────────────────────────────────────

function fetchText(string $url, int $timeout = 10): string|false
{
    if (!isSafeUrl($url)) {
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'LLMRBot/2.0 (+https://open-llmr.org)',
    ]);
    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $httpCode < 200 || $httpCode >= 400) {
        return false;
    }
    return (string) $body;
}

// ─── Sitemap discovery ────────────────────────────────────────────────────────

function discoverSitemap(string $origin): string|false
{
    $candidates = [
        $origin . '/sitemap.xml',
        $origin . '/sitemap_index.xml',
        $origin . '/sitemap/sitemap.xml',
    ];
    foreach ($candidates as $candidate) {
        $body = fetchText($candidate, 8);
        if ($body !== false && str_contains($body, '<loc>')) {
            return $candidate;
        }
    }
    // Parse robots.txt for Sitemap: directive
    $robots = fetchText($origin . '/robots.txt', 5);
    if ($robots !== false && preg_match('/^Sitemap:\s*(.+)$/im', $robots, $m)) {
        $found = trim($m[1]);
        if (isSafeUrl($found)) {
            return $found;
        }
    }
    return false;
}

// ─── Sitemap URL extraction ───────────────────────────────────────────────────

function extractLocsFromXml(string $xml): array
{
    // Works for both sitemap_index and urlset formats
    preg_match_all('|<loc>\s*(https?://[^<\s]+)\s*</loc>|', $xml, $m);
    return array_map('trim', $m[1] ?? []);
}

function getUrlsFromSitemap(string $sitemapUrl): array
{
    $xml = fetchText($sitemapUrl, 10);
    if ($xml === false) {
        return [];
    }
    $locs = extractLocsFromXml($xml);
    if (!$locs) {
        return [];
    }

    // Sitemap index: all locs point to child sitemaps (end in .xml)
    // Detect via root element or by checking if locs look like sitemaps
    $isSitemapIndex = str_contains($xml, '<sitemapindex') || str_contains($xml, '<sitemap>');
    if ($isSitemapIndex) {
        // Fetch the first child sitemap and get page URLs from it
        $childUrl = $locs[0] ?? '';
        if (!$childUrl || !isSafeUrl($childUrl)) {
            return [];
        }
        $childXml = fetchText($childUrl, 10);
        if ($childXml === false) {
            return [];
        }
        return extractLocsFromXml($childXml);
    }

    return $locs;
}

// ─── IP rate limiting ─────────────────────────────────────────────────────────

function checkRateLimit(string $ip, string $logFile): array
{
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Ensure .htaccess blocks direct HTTP access to data/
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }

    $data = [];
    if (file_exists($logFile)) {
        $data = json_decode((string) file_get_contents($logFile), true) ?? [];
    }

    $now = time();

    // Remove entries older than 48 hours
    $data = array_filter($data, fn($ts) => ($now - (int) $ts) < 172800);

    if (isset($data[$ip])) {
        $nextAvail = (int) $data[$ip] + 86400;
        if ($now < $nextAvail) {
            return ['limited' => true, 'next' => date('c', $nextAvail)];
        }
    }

    $data[$ip] = $now;
    file_put_contents($logFile, json_encode($data), LOCK_EX);
    return ['limited' => false];
}

// ─── Parallel page fetch ──────────────────────────────────────────────────────

function fetchPagesParallel(array $urls, int $timeout = 8, int $maxBytes = 524288): array
{
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urls as $i => $url) {
        if (!isSafeUrl($url)) {
            continue;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'LLMRBot/2.0 (+https://open-llmr.org)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['ch' => $ch, 'url' => $url];
    }

    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $item) {
        $ch          = $item['ch'];
        $url         = $item['url'];
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
        $body        = (string) (curl_multi_getcontent($ch) ?? '');

        if ($httpCode === 200 && str_contains($contentType, 'html')) {
            $results[$url] = substr($body, 0, $maxBytes);
        }

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

// ─── Metadata extraction ──────────────────────────────────────────────────────

function extractMeta(string $html, string $url): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    $meta = fn(string $n) => (string) ($xpath->query("//meta[@name='{$n}']")->item(0)?->getAttribute('content') ?? '');
    $prop = fn(string $n) => (string) ($xpath->query("//meta[@property='{$n}']")->item(0)?->getAttribute('content') ?? '');
    $link = fn(string $r) => (string) ($xpath->query("//link[@rel='{$r}']")->item(0)?->getAttribute('href') ?? '');
    $text = fn(string $q) => (string) ($xpath->query($q)->item(0)?->nodeValue ?? '');

    $title = $prop('og:title') ?: $meta('twitter:title') ?: $text('//title');

    $description = $prop('og:description')
                ?: $meta('description')
                ?: $meta('twitter:description');

    $author = $meta('author') ?: $text('//a[@rel="author"][1]') ?: '';

    $date = $prop('article:published_time') ?: $text('//time[@datetime][1]') ?: '';
    if ($date) {
        preg_match('/\d{4}-\d{2}-\d{2}/', $date, $dm);
        $date = $dm[0] ?? '';
    }

    // Tags: meta keywords + h2 headings + article:tag meta
    $kwStr = $meta('keywords');
    $tags  = $kwStr !== '' ? array_slice(array_map('trim', explode(',', $kwStr)), 0, 5) : [];

    foreach ($xpath->query('//h2') as $h2) {
        $t = mb_strtolower(trim((string) $h2->nodeValue));
        if (mb_strlen($t) >= 3 && mb_strlen($t) <= 40 && !in_array($t, $tags, true)) {
            $tags[] = $t;
        }
    }
    foreach ($xpath->query('//meta[@property="article:tag"]') as $at) {
        $t = trim((string) $at->getAttribute('content'));
        if ($t && !in_array($t, $tags, true)) {
            $tags[] = $t;
        }
    }
    $tags = array_values(array_unique(array_slice($tags, 0, 8)));

    $bodyText    = $text('//body');
    $wordCount   = str_word_count(strip_tags($bodyText));
    $readingTime = $wordCount > 0 ? max(1, (int) round($wordCount / 200)) : 0;

    $codeBlocks = $xpath->query('//pre | //code[contains(@class,"language-") or contains(@class,"hljs")]')->length;

    $canonical = $link('canonical') ?: $url;
    $path      = parse_url($canonical, PHP_URL_PATH) ?: '/';

    return [
        'title'       => trim($title),
        'description' => trim($description),
        'author'      => trim($author),
        'date'        => $date,
        'tags'        => $tags,
        'wordCount'   => $wordCount,
        'readingTime' => $readingTime,
        'codeBlocks'  => (int) $codeBlocks,
        'path'        => $path,
    ];
}

// ─── Slug generation ──────────────────────────────────────────────────────────

function makeSlug(string $path, string $title): string
{
    $clean = trim($path, '/');
    if ($clean !== '' && !in_array($clean, ['index.html', 'index.php', 'index'], true)) {
        return $clean;
    }
    $slug = mb_strtolower($title);
    $slug = preg_replace('/[^a-z0-9\s-]+/', '', $slug) ?? '';
    $slug = trim(preg_replace('/[\s-]+/', '-', $slug) ?? '', '-');
    return $slug ?: 'home';
}

// ─── Mistral summary ──────────────────────────────────────────────────────────

function mistralSummary(array $meta, string $key, string $model, string $endpoint, int $timeout): array
{
    $prompt = "Write a single sentence (max 25 words) summarising this web page for an AI site index (LLMR format).\n"
            . "Title: {$meta['title']}\n"
            . "Description: {$meta['description']}\n"
            . "Return ONLY the summary sentence. No JSON, no quotes, no markdown.";

    $payload = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.2,
        'max_tokens'  => 60,
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['', "Mistral unreachable: {$curlErr}"];
    }
    if ($httpCode === 401) {
        return ['', 'Mistral API key invalid.'];
    }
    if ($httpCode === 429) {
        return ['', 'Mistral rate limited.'];
    }
    if ($httpCode === 503 || $httpCode === 529) {
        return ['', 'Mistral overloaded.'];
    }
    if ($httpCode !== 200) {
        $errBody = json_decode((string) $resp, true);
        $msg = $errBody['detail'] ?? $errBody['message'] ?? $errBody['error']['message'] ?? "HTTP {$httpCode}";
        return ['', "Mistral error: {$msg}"];
    }
    $data    = json_decode((string) $resp, true);
    $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    return $content !== '' ? [$content, null] : ['', 'Mistral returned empty response.'];
}

// ─── Build page object ────────────────────────────────────────────────────────

function buildPage(array $meta, string $pageUrl, string $summary): array
{
    $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
    $slug = makeSlug($path, $meta['title']);
    $page = ['id' => $slug, 'u' => $path];

    if ($meta['title'] !== '') {
        $page['ti'] = mb_substr($meta['title'], 0, 100);
    }
    if ($meta['date'] !== '') {
        $page['d'] = $meta['date'];
    }
    if ($summary !== '') {
        $page['sum'] = $summary;
    }
    if ($meta['tags']) {
        $page['kw'] = $meta['tags'];
    }
    if ($meta['wordCount'] > 0) {
        $page['wc'] = $meta['wordCount'];
    }
    if ($meta['readingTime'] > 0) {
        $page['rt'] = $meta['readingTime'];
    }
    if ($meta['codeBlocks'] > 0) {
        $page['cb'] = $meta['codeBlocks'];
    }

    $techKw      = ['code', 'debug', 'api', 'database', 'script', 'function', 'error', 'bug', 'deploy'];
    $contentText = mb_strtolower($meta['title'] . ' ' . $meta['description']);
    foreach ($techKw as $kw) {
        if (str_contains($contentText, $kw)) {
            $page['tc'] = 1;
            break;
        }
    }

    return $page;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

$body       = json_decode((string) file_get_contents('php://input'), true) ?? [];
$inputUrl   = trim((string) ($body['url'] ?? ''));
$sitemapUrl = trim((string) ($body['sitemap_url'] ?? ''));

if ($inputUrl === '' || !filter_var($inputUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_url']);
    exit;
}
if (!isSafeUrl($inputUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsafe_url']);
    exit;
}

$scheme = strtolower((string) parse_url($inputUrl, PHP_URL_SCHEME));
$host   = (string) parse_url($inputUrl, PHP_URL_HOST);
$origin = $scheme . '://' . $host;

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// MODE A — Discovery only (no sitemap_url provided)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($sitemapUrl === '') {
    $discovered = discoverSitemap($origin);
    if ($discovered === false) {
        echo json_encode(['error' => 'sitemap_not_found']);
        exit;
    }
    $urls  = getUrlsFromSitemap($discovered);
    $urls  = array_values(array_unique(array_filter($urls, 'isSafeUrl')));
    echo json_encode(['sitemap_url' => $discovered, 'pages_found' => count($urls)]);
    exit;
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// MODE B — Full processing (sitemap_url provided)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// ─── IP rate limit — 1 generation per IP per 24 h ────────────────────────────
$ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$logFile = __DIR__ . '/../data/ip_log.json';
// RATE LIMIT DISABLED FOR TESTING — re-enable before production.
$rate    = ['limited' => false];
// $rate = checkRateLimit($ip, $logFile);
if ($rate['limited']) {
    echo json_encode(['error' => 'rate_limited', 'next_available' => $rate['next']]);
    exit;
}

// Validate user-supplied sitemap URL
if (!filter_var($sitemapUrl, FILTER_VALIDATE_URL) || !isSafeUrl($sitemapUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_sitemap_url']);
    exit;
}

// Get URLs
$allUrls  = getUrlsFromSitemap($sitemapUrl);
$allUrls  = array_values(array_unique(array_filter($allUrls, 'isSafeUrl')));
if (!$allUrls) {
    echo json_encode(['error' => 'sitemap_empty']);
    exit;
}

$wasCapped = count($allUrls) > 25;
if ($wasCapped) {
    shuffle($allUrls);
}
$urls = array_slice($allUrls, 0, 25);

// Parallel fetch
$htmlByUrl = fetchPagesParallel($urls);
if (!$htmlByUrl) {
    echo json_encode(['error' => 'fetch_failed']);
    exit;
}

// Extract metadata
$pagesData = [];
foreach ($htmlByUrl as $pageUrl => $html) {
    $pagesData[] = ['url' => $pageUrl, 'meta' => extractMeta($html, $pageUrl), 'summary' => ''];
}

// Mistral summaries — sequential with 300ms delay
$mistralIssueCount = 0;
$lastMistralWarn   = '';

foreach ($pagesData as &$item) {
    $meta = $item['meta'];
    if ($MISTRAL_KEY !== '') {
        [$sum, $warn] = mistralSummary($meta, $MISTRAL_KEY, $MISTRAL_MODEL, $MISTRAL_ENDPOINT, $MISTRAL_TIMEOUT);
        $item['summary'] = $sum ?: mb_substr($meta['description'] ?: $meta['title'] ?: '', 0, 300);
        if ($warn) {
            $mistralIssueCount++;
            $lastMistralWarn = $warn;
        }
        usleep(300000); // 300 ms between calls
    } else {
        $item['summary'] = mb_substr($meta['description'] ?: $meta['title'] ?: '', 0, 300);
    }
}
unset($item);

// Build page objects
$pages = [];
foreach ($pagesData as $item) {
    $pages[] = buildPage($item['meta'], $item['url'], $item['summary']);
}

// Sort by date descending
usort($pages, fn($a, $b) => strcmp($b['d'] ?? '', $a['d'] ?? ''));

// Find author from pages
$siteAuthor = '';
foreach ($pagesData as $item) {
    if ($item['meta']['author'] !== '') {
        $siteAuthor = $item['meta']['author'];
        break;
    }
}

// Build LLMR structure
$site = ['d' => $host];
if ($siteAuthor !== '') {
    $site['a'] = ['n' => $siteAuthor, 'r' => ''];
}

$llmr = [
    'v'  => '2.0',
    'ts' => time(),
    's'  => $site,
    'p'  => $pages,
];

// Stats
$pageCount    = count($pages);
$estHtmlBytes = $pageCount * 27500;
$llmrBytes    = strlen(json_encode($llmr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$reductionPct = $estHtmlBytes > 0 ? round((1 - $llmrBytes / $estHtmlBytes) * 100, 1) : 0;

$stats = [
    'pages'          => $pageCount,
    'est_html_bytes' => $estHtmlBytes,
    'llmr_bytes'     => $llmrBytes,
    'reduction_pct'  => $reductionPct,
    'mistral_used'   => $MISTRAL_KEY !== '',
];

// Response
$response = ['llmr' => $llmr, 'stats' => $stats];
if ($wasCapped) {
    $response['warning'] = 'capped_at_25';
}
if ($mistralIssueCount > 0) {
    $response['warning_mistral'] = "Mistral had issues on {$mistralIssueCount} page(s): {$lastMistralWarn}";
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
