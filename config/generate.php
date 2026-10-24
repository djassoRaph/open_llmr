<?php
/**
 * config/generate.php — LLMR multi-page sitemap generator endpoint
 *
 * Requests: POST, Content-Type: application/json, from this site only (no CORS headers;
 * a cross-site Sec-Fetch-Site or a foreign Origin is refused).
 *
 * Mode A — Discovery (no sitemap_url in body, or "mode": "discover"):
 *   Rate-limits (30 per IP per hour), discovers the sitemap (or reads the given
 *   sitemap_url), returns { sitemap_url, pages_found, pages_capped? } or { error }
 *
 * Mode B — Processing (sitemap_url provided):
 *   Checks the daily limit, counts the request against the hourly limit, validates
 *   all input, then records the generation (1 per IP per 24 h), fetches pages in
 *   parallel, calls Mistral per-page, returns { llmr, stats, warning?, warning_mistral? }
 *
 * Every outbound fetch (sitemaps, robots.txt, pages, llms.txt) goes through
 * fetchMany(): http/https on port 80 or 443 only, every A/AAAA record of the host
 * must be public and not this server's own address, curl is pinned to the checked
 * address, redirects are followed by hand and checked again at each hop within one
 * time budget, and bodies are capped while streaming.
 * Sitemaps and pages must stay on the submitted host (or its www./apex twin).
 *
 * tools/test_generate.php loads this file as a library by defining LLMR_GENERATE_LIB.
 */

declare(strict_types=1);

// ─── Limits ───────────────────────────────────────────────────────────────────

const LLMR_VERSION    = '2.1';
const LLMR_UA         = 'LLMRBot/2.1 (+https://open-llmr.org)';

const MAX_REDIRECTS   = 3;
const CONNECT_TIMEOUT = 5;                  // seconds
const CAP_PAGE        = 2 * 1024 * 1024;    // HTML page
const CAP_SITEMAP     = 5 * 1024 * 1024;    // sitemap or sitemap index
const CAP_TEXT        = 256 * 1024;         // robots.txt, llms.txt
const MAX_LOCS        = 5000;               // <loc> entries read from one sitemap
const MAX_PAGES       = 25;                 // pages processed per generation

const DISCOVERY_LIMIT   = 30;               // Mode A: lookups per IP ...
const DISCOVERY_WINDOW  = 3600;             // ... per hour
const GENERATION_LIMIT  = 1;                // Mode B: generations per IP ...
const GENERATION_WINDOW = 86400;            // ... per 24 h

const ACCEPT_HTML = 'text/html,application/xhtml+xml';
const ACCEPT_XML  = 'application/xml,text/xml;q=0.9,*/*;q=0.8';
const ACCEPT_TEXT = 'text/plain,text/markdown;q=0.9,*/*;q=0.8';

// Addresses we never connect to. IPv6 is checked against an allowlist (global
// unicast 2000::/3) first, so only blocked ranges inside it are listed.
const BLOCKED_V4 = [
    '0.0.0.0/8',        // "this network", unspecified
    '10.0.0.0/8',       // private
    '100.64.0.0/10',    // carrier-grade NAT (also cloud metadata, e.g. 100.100.100.200)
    '127.0.0.0/8',      // loopback
    '169.254.0.0/16',   // link-local (cloud metadata 169.254.169.254)
    '172.16.0.0/12',    // private
    '192.0.0.0/24',     // IETF protocol assignments
    '192.0.2.0/24',     // documentation
    '192.88.99.0/24',   // 6to4 relay anycast
    '192.168.0.0/16',   // private
    '198.18.0.0/15',    // benchmarking
    '198.51.100.0/24',  // documentation
    '203.0.113.0/24',   // documentation
    '224.0.0.0/4',      // multicast
    '240.0.0.0/4',      // reserved, broadcast
];
const BLOCKED_V6 = [
    '2001::/23',        // IETF protocol assignments (Teredo, benchmarking, ORCHID)
    '2001:db8::/32',    // documentation
    '3fff::/20',        // documentation
];

// This server's public addresses: never fetched, since a request to them reaches this
// site and every vhost sharing the machine. LLMR_OWN_IPS (comma or space separated, in
// .env or the environment) replaces this list; $_SERVER['SERVER_ADDR'] is always added.
const OWN_IPS = ['109.234.166.135'];

// The only page allowed to call this endpoint
const ALLOWED_ORIGIN = 'https://open-llmr.org';

// ─── Test seams ───────────────────────────────────────────────────────────────

/** DNS, HTTP and clock hooks. Null means the real thing; the test harness swaps in stubs. */
final class Net
{
    /** fn(string $host): list<string>|false */
    public static ?Closure $resolver = null;
    /** fn(array $requests): array, one response per request key (see curlExchange) */
    public static ?Closure $transport = null;
    /** fn(): float, seconds on a monotonic clock */
    public static ?Closure $clock = null;
    /** Per-request DNS answers, so one host is looked up once and every fetch pins the same address */
    public static array $dnsCache = [];
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

// ─── SSRF guard: addresses ────────────────────────────────────────────────────

function cidrMatch(string $packed, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr);
    $netPacked = inet_pton($net);
    if ($netPacked === false || strlen($netPacked) !== strlen($packed)) {
        return false;
    }
    $bits  = (int) $bits;
    $bytes = intdiv($bits, 8);
    if (substr($packed, 0, $bytes) !== substr($netPacked, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($packed[$bytes]) & $mask) === (ord($netPacked[$bytes]) & $mask);
}

function isPublicIp(string $ip): bool
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return false;
    }

    if (strlen($packed) === 16) {
        // IPv4 wrapped in IPv6 (mapped ::ffff:0:0/96, NAT64 64:ff9b::/96): judge the IPv4 inside
        if (cidrMatch($packed, '::ffff:0:0/96') || cidrMatch($packed, '64:ff9b::/96')) {
            return isPublicIp((string) inet_ntop(substr($packed, 12)));
        }
        // 6to4 carries an IPv4 address in bits 16-47
        if (cidrMatch($packed, '2002::/16') && !isPublicIp((string) inet_ntop(substr($packed, 2, 4)))) {
            return false;
        }
        // Everything outside global unicast is refused: ::, ::1, fc00::/7, fe80::/10, ff00::/8, ...
        if (!cidrMatch($packed, '2000::/3')) {
            return false;
        }
        foreach (BLOCKED_V6 as $cidr) {
            if (cidrMatch($packed, $cidr)) {
                return false;
            }
        }
        return true;
    }

    foreach (BLOCKED_V4 as $cidr) {
        if (cidrMatch($packed, $cidr)) {
            return false;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/** Packed address, with an IPv4 wrapped in IPv6 (mapped, NAT64) unwrapped; false if invalid. */
function packedUnwrapped(string $ip): string|false
{
    $packed = @inet_pton(trim($ip));
    if ($packed !== false && strlen($packed) === 16
        && (cidrMatch($packed, '::ffff:0:0/96') || cidrMatch($packed, '64:ff9b::/96'))) {
        return substr($packed, 12);
    }
    return $packed;
}

/** True when $ip is this server: SERVER_ADDR, or OWN_IPS (replaced by LLMR_OWN_IPS when set). */
function isOwnAddress(string $ip): bool
{
    $configured = $_ENV['LLMR_OWN_IPS'] ?? getenv('LLMR_OWN_IPS');
    $own = is_string($configured) && trim($configured) !== ''
         ? (array) preg_split('/[\s,]+/', trim($configured))
         : OWN_IPS;
    $own[] = (string) ($_SERVER['SERVER_ADDR'] ?? '');

    $packed = packedUnwrapped($ip);
    if ($packed === false) {
        return false;
    }
    foreach ($own as $candidate) {
        if (packedUnwrapped((string) $candidate) === $packed) {
            return true;
        }
    }
    return false;
}

// ─── SSRF guard: hosts and URLs ───────────────────────────────────────────────

/**
 * Normalise a URL host to [host, kind] with kind 'ipv4', 'ipv6' or 'name', or false.
 * Only canonical dotted-quad IPv4 is accepted: decimal (2130706433), octal (0177.0.0.1),
 * hex (0x7f.0.0.1) and short (127.1) forms are refused, because curl reads them as IPs.
 */
function normalizeHost(string $host): array|false
{
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $literal = substr($host, 1, -1);
        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false; // includes zone IDs such as fe80::1%eth0
        }
        return [(string) inet_ntop((string) inet_pton($literal)), 'ipv6'];
    }

    $host = strtolower($host);
    if (str_ends_with($host, '.')) {
        $host = substr($host, 0, -1);
    }

    $octet = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';
    if (preg_match("/^{$octet}(?:\\.{$octet}){3}$/", $host)) {
        return [$host, 'ipv4'];
    }

    if (preg_match('/[^\x21-\x7e]/', $host)) {
        // Internationalised domain name: compare and connect in its ASCII (punycode) form
        if (!function_exists('idn_to_ascii')) {
            return false;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            return false;
        }
        $host = strtolower($ascii);
    }

    if (strlen($host) > 253) {
        return false;
    }
    $labels = explode('.', $host);
    if (count($labels) < 2) {
        return false; // localhost, intranet names, bare numbers
    }
    foreach ($labels as $label) {
        if (!preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/', $label)) {
            return false;
        }
    }
    // A numeric or 0x last label makes curl read the whole host as an IPv4 address
    $tld = end($labels);
    if (!preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $tld)) {
        return false;
    }
    $special = ['localhost', 'local', 'localdomain', 'internal', 'intranet', 'lan', 'home', 'corp',
                'test', 'example', 'invalid', 'onion', 'arpa'];
    if (in_array($tld, $special, true)) {
        return false;
    }
    return [$host, 'name'];
}

/** The submitted host plus its www./apex twin. */
function scopeHosts(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return [$host];
    }
    $apex = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    return array_values(array_unique([$host, $apex, 'www.' . $apex]));
}

/** Cheap check, no DNS: is this an http(s) URL on port 80/443 of one of the scope hosts? */
function inScope(string $url, array $scope): bool
{
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
        return false;
    }
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
        return false;
    }
    if (isset($p['port']) && $p['port'] !== 80 && $p['port'] !== 443) {
        return false;
    }
    $h = normalizeHost($p['host']);
    return $h !== false && in_array($h[0], $scope, true);
}

/** All A and AAAA records of a host, or false when it does not resolve. */
function resolveHost(string $host): array|false
{
    if (array_key_exists($host, Net::$dnsCache)) {
        return Net::$dnsCache[$host];
    }

    if (Net::$resolver !== null) {
        $ips = (Net::$resolver)($host);
    } elseif (function_exists('dns_get_record')) {
        // Trailing dot: an absolute name, so the resolver's search domains are never tried
        $records = @dns_get_record($host . '.', DNS_A | DNS_AAAA);
        $ips     = $records === false ? false : [];
        foreach ($records ?: [] as $r) {
            if (($r['type'] ?? '') === 'A' && isset($r['ip'])) {
                $ips[] = $r['ip'];
            } elseif (($r['type'] ?? '') === 'AAAA' && isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    } else {
        $ips = @gethostbynamel($host); // IPv4 only; we pin to what we checked, so AAAA is never used
    }

    $ips = is_array($ips) && $ips ? array_values(array_unique($ips)) : false;
    return Net::$dnsCache[$host] = $ips;
}

/** The address curl is pinned to: IPv4 first (shared hosting may lack IPv6 routes). */
function pickAddress(array $ips): string
{
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $ip;
        }
    }
    $packed = (string) inet_pton($ips[0]);
    if (cidrMatch($packed, '::ffff:0:0/96')) {
        return (string) inet_ntop(substr($packed, 12));
    }
    return (string) inet_ntop($packed);
}

/** Percent-encode bytes curl would otherwise send raw (controls, space, non-ASCII). */
function encodeUrlPart(string $part): string
{
    return preg_replace_callback('/[^\x21-\x7e]/', fn(array $m) => rawurlencode($m[0]), $part) ?? '';
}

function refused(string $reason): array
{
    return ['ok' => false, 'error' => $reason];
}

/**
 * Check one URL against the guard and resolve it.
 *
 * Returns ['ok' => true, url, origin, scheme, host, kind, port, ip] where url is rebuilt
 * from the parsed parts (so curl parses exactly what we checked) and ip is the address
 * to pin; or ['ok' => false, 'error' => reason]. Reasons: bad_url, bad_scheme,
 * credentials, bad_host, bad_port, off_origin, dns_failed, private_address, own_address.
 *
 * @param list<string>|null $scope allowed hosts, or null for any public host
 */
function prepareRequest(string $url, ?array $scope): array
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        return refused('bad_url');
    }
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'])) {
        return refused('bad_url');
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return refused('bad_scheme');
    }
    if (!isset($p['host']) || $p['host'] === '') {
        return refused('bad_url');
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return refused('credentials');
    }

    $h = normalizeHost($p['host']);
    if ($h === false) {
        return refused('bad_host');
    }
    [$host, $kind] = $h;

    $defaultPort = $scheme === 'https' ? 443 : 80;
    $port        = $p['port'] ?? $defaultPort;
    if ($port !== 80 && $port !== 443) {
        return refused('bad_port');
    }
    if ($scope !== null && !in_array($host, $scope, true)) {
        return refused('off_origin');
    }

    $ips = $kind === 'name' ? resolveHost($host) : [$host];
    if (!$ips) {
        return refused('dns_failed');
    }
    foreach ($ips as $ip) {
        if (!isPublicIp($ip)) {
            return refused('private_address');
        }
        if (isOwnAddress($ip)) {
            return refused('own_address');
        }
    }

    $origin = $scheme . '://' . ($kind === 'ipv6' ? "[{$host}]" : $host)
            . ($port !== $defaultPort ? ':' . $port : '');
    $path   = encodeUrlPart($p['path'] ?? '') ?: '/';
    $query  = isset($p['query']) ? '?' . encodeUrlPart($p['query']) : '';

    return [
        'ok'     => true,
        'url'    => $origin . $path . $query,
        'origin' => $origin,
        'scheme' => $scheme,
        'host'   => $host,
        'kind'   => $kind,
        'port'   => $port,
        'ip'     => pickAddress($ips),
    ];
}

/** Resolve a Location header against the URL that sent it (RFC 3986, section 5). */
function resolveUrl(string $base, string $ref): string
{
    $ref = trim($ref);
    if (($hash = strpos($ref, '#')) !== false) {
        $ref = substr($ref, 0, $hash);
    }
    if ($ref === '') {
        return $base;
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $ref)) {
        return $ref; // absolute, any scheme: prepareRequest decides
    }

    $b         = parse_url($base);
    $scheme    = $b['scheme'] ?? 'https';
    $authority = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($ref, '//')) {
        return $scheme . ':' . $ref;
    }

    $basePath = $b['path'] ?? '/';
    if ($ref[0] === '?') {
        return $scheme . '://' . $authority . $basePath . $ref;
    }

    [$refPath, $refQuery] = explode('?', $ref, 2) + ['', null];
    $path = $refPath[0] === '/' ? $refPath : preg_replace('#[^/]*$#', '', $basePath) . $refPath;

    $out = [];
    $segments = explode('/', $path);
    foreach ($segments as $i => $seg) {
        if ($seg === '.' || $seg === '..') {
            if ($seg === '..' && count($out) > 1) {
                array_pop($out);
            }
            if ($i === count($segments) - 1) {
                $out[] = '';
            }
            continue;
        }
        $out[] = $seg;
    }
    $path = implode('/', $out);
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }

    return $scheme . '://' . $authority . $path . ($refQuery !== null ? '?' . $refQuery : '');
}

// ─── Guarded HTTP fetch ───────────────────────────────────────────────────────

/**
 * One round of HTTP requests over curl_multi. Each request must come from prepareRequest().
 * Redirects are not followed here; the Location header is returned instead.
 *
 * @return array<string|int,array{status:int,type:string,location:string,body:string,truncated:bool,error:string}>
 */
function curlExchange(array $requests): array
{
    $mh   = curl_multi_init();
    $jobs = [];
    $out  = [];

    foreach ($requests as $key => $req) {
        $state = (object) ['body' => '', 'truncated' => false, 'location' => ''];
        $cap   = $req['cap'];
        $addr  = str_contains($req['ip'], ':') ? '[' . $req['ip'] . ']' : $req['ip'];

        $opts = [
            CURLOPT_URL             => $req['url'],
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // Pin the connection to the address we checked: no second DNS lookup, no rebinding
            CURLOPT_CONNECT_TO      => ["::{$addr}:{$req['port']}"],
            CURLOPT_PROXY           => '',
            // What is left of the fetch's budget (fetchMany never passes 0, which curl reads as "no limit")
            CURLOPT_CONNECTTIMEOUT_MS => min(CONNECT_TIMEOUT * 1000, $req['timeout_ms']),
            CURLOPT_TIMEOUT_MS      => $req['timeout_ms'],
            CURLOPT_NOSIGNAL        => true,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => LLMR_UA,
            CURLOPT_HTTPHEADER      => ['Accept: ' . $req['accept']],
            CURLOPT_HEADERFUNCTION  => function ($ch, string $line) use ($state): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#i', $line)) {
                    $state->location = ''; // a new response block (after 100 Continue)
                } elseif (stripos($line, 'location:') === 0) {
                    $state->location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            // Size cap while streaming: keep up to $cap bytes, then abort the transfer
            CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use ($state, $cap): int {
                $room = $cap - strlen($state->body);
                if (strlen($chunk) <= $room) {
                    $state->body .= $chunk;
                    return strlen($chunk);
                }
                $state->body     .= substr($chunk, 0, max(0, $room));
                $state->truncated = true;
                return 0; // fewer bytes than received: curl stops with CURLE_WRITE_ERROR
            },
        ];
        if ($req['kind'] === 'name') {
            $opts[CURLOPT_RESOLVE] = ["{$req['host']}:{$req['port']}:{$addr}"];
        }

        $ch = curl_init();
        if (!curl_setopt_array($ch, $opts)) {
            curl_close($ch);
            $out[$key] = ['status' => 0, 'type' => '', 'location' => '', 'body' => '', 'truncated' => false, 'error' => 'transport_setup'];
            continue;
        }
        curl_multi_add_handle($mh, $ch);
        $jobs[$key] = [$ch, $state, $req];
    }

    $results = [];
    do {
        $status = curl_multi_exec($mh, $active);
        while ($info = curl_multi_info_read($mh)) {
            $results[spl_object_id($info['handle'])] = $info['result'];
        }
        if ($active && curl_multi_select($mh, 1.0) === -1) {
            usleep(10000);
        }
    } while ($active && $status === CURLM_OK);
    while ($info = curl_multi_info_read($mh)) {
        $results[spl_object_id($info['handle'])] = $info['result'];
    }

    foreach ($jobs as $key => [$ch, $state, $req]) {
        $code    = $results[spl_object_id($ch)] ?? -1;
        $primary = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);

        $error = '';
        if ($code !== CURLE_OK && !($code === CURLE_WRITE_ERROR && $state->truncated)) {
            $error = 'transport';
        } elseif (@inet_pton($primary) !== @inet_pton($req['ip'])) {
            $error = 'pin_mismatch'; // never expected; refuse rather than trust the body
        }

        $out[$key] = [
            'status'    => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'type'      => (string) (curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? ''),
            'location'  => $state->location,
            'body'      => $error === '' ? $state->body : '',
            'truncated' => $state->truncated,
            'error'     => $error,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
}

function fetchFailure(string $url, string $error, int $status = 0): array
{
    return ['ok' => false, 'status' => $status, 'type' => '', 'body' => '', 'url' => $url, 'truncated' => false, 'error' => $error];
}

/** Seconds on a monotonic clock. */
function clockNow(): float
{
    return Net::$clock !== null ? (Net::$clock)() : hrtime(true) / 1e9;
}

/**
 * Fetch URLs in parallel through the guard. Redirects (up to MAX_REDIRECTS) are followed
 * by hand, and every hop is checked again: scheme, port, scope, DNS and address.
 * $opt['timeout'] is the budget of each whole fetch, redirects included: every hop gets
 * what is left of it, and a fetch whose budget is spent fails with 'timeout'.
 *
 * @param array<string|int,string> $urls
 * @param array{cap:int,timeout:int,accept:string,scope:?array} $opt
 * @return array<string|int,array{ok:bool,status:int,type:string,body:string,url:string,truncated:bool,error:string}>
 *         same keys as $urls; url is the final URL, ok means a 2xx body (possibly truncated)
 */
function fetchMany(array $urls, array $opt): array
{
    $transport = Net::$transport ?? curlExchange(...);
    $results   = [];
    $pending   = $urls;
    $deadline  = clockNow() + $opt['timeout']; // all fetches start together, so they share it

    for ($hop = 0; $pending; $hop++) {
        $requests = [];
        foreach ($pending as $key => $url) {
            if (clockNow() >= $deadline) {
                $results[$key] = fetchFailure($url, 'timeout');
                continue;
            }
            $req = prepareRequest($url, $opt['scope']);
            if (!$req['ok']) {
                $results[$key] = fetchFailure($url, $req['error']);
                continue;
            }
            $requests[$key] = $req + ['cap' => $opt['cap'], 'accept' => $opt['accept']];
        }

        // Measured after DNS, so lookups come out of the same budget; never 0 (no limit to curl)
        $leftMs = (int) floor(($deadline - clockNow()) * 1000);
        foreach ($requests as $key => $req) {
            if ($leftMs < 1) {
                $results[$key] = fetchFailure($req['url'], 'timeout');
                unset($requests[$key]);
                continue;
            }
            $requests[$key]['timeout_ms'] = $leftMs;
        }

        $pending   = [];
        $responses = $requests ? $transport($requests) : [];

        foreach ($requests as $key => $req) {
            $r      = $responses[$key] ?? ['status' => 0, 'error' => 'transport'];
            $status = (int) ($r['status'] ?? 0);
            $error  = (string) ($r['error'] ?? '');

            if ($error === '' && in_array($status, [301, 302, 303, 307, 308], true) && ($r['location'] ?? '') !== '') {
                if ($hop >= MAX_REDIRECTS) {
                    $results[$key] = fetchFailure($req['url'], 'too_many_redirects', $status);
                } else {
                    $pending[$key] = resolveUrl($req['url'], (string) $r['location']);
                }
                continue;
            }

            $results[$key] = [
                'ok'        => $error === '' && $status >= 200 && $status < 300,
                'status'    => $status,
                'type'      => (string) ($r['type'] ?? ''),
                'body'      => $error === '' ? (string) ($r['body'] ?? '') : '',
                'url'       => $req['url'],
                'truncated' => (bool) ($r['truncated'] ?? false),
                'error'     => $error,
            ];
        }
    }

    $ordered = [];
    foreach ($urls as $key => $_) {
        $ordered[$key] = $results[$key];
    }
    return $ordered;
}

function guardedFetch(string $url, int $cap, int $timeout, ?array $scope, string $accept): array
{
    return fetchMany(['url' => $url], ['cap' => $cap, 'timeout' => $timeout, 'accept' => $accept, 'scope' => $scope])['url'];
}

function fetchText(string $url, int $cap, int $timeout, ?array $scope, string $accept): string|false
{
    $r = guardedFetch($url, $cap, $timeout, $scope, $accept);
    return $r['ok'] ? $r['body'] : false;
}

// ─── Sitemap discovery ────────────────────────────────────────────────────────

function discoverSitemap(string $origin, array $scope): string|false
{
    $candidates = [
        $origin . '/sitemap.xml',
        $origin . '/sitemap_index.xml',
        $origin . '/sitemap/sitemap.xml',
    ];
    foreach ($candidates as $candidate) {
        $body = fetchText($candidate, CAP_SITEMAP, 8, $scope, ACCEPT_XML);
        if ($body !== false && str_contains($body, '<loc>')) {
            return $candidate;
        }
    }
    // Parse robots.txt for Sitemap: directives, keeping the first one on this site
    $robots = fetchText($origin . '/robots.txt', CAP_TEXT, 5, $scope, ACCEPT_TEXT);
    if ($robots !== false && preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $robots, $m)) {
        foreach ($m[1] as $found) {
            if (inScope($found, $scope)) {
                return $found;
            }
        }
    }
    return false;
}

// ─── Sitemap URL extraction ───────────────────────────────────────────────────

function extractLocsFromXml(string $xml): array
{
    // Works for both sitemap_index and urlset formats
    preg_match_all('|<loc>\s*(https?://[^<\s]+)\s*</loc>|', $xml, $m);
    return array_map(fn(string $loc) => html_entity_decode(trim($loc), ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1] ?? []);
}

/**
 * Page URLs listed in a sitemap (or in the first child of a sitemap index) that are on
 * this site. Reads at most MAX_LOCS entries and does no DNS lookup per entry.
 *
 * @return array{0: list<string>, 1: bool} [urls, capped]
 */
function getUrlsFromSitemap(string $sitemapUrl, array $scope): array
{
    $r = guardedFetch($sitemapUrl, CAP_SITEMAP, 10, $scope, ACCEPT_XML);
    if (!$r['ok']) {
        return [[], false];
    }
    $xml  = $r['body'];
    $locs = extractLocsFromXml($xml);
    if (!$locs) {
        return [[], false];
    }

    // Sitemap index: all locs point to child sitemaps (end in .xml)
    // Detect via root element or by checking if locs look like sitemaps
    $isSitemapIndex = str_contains($xml, '<sitemapindex') || str_contains($xml, '<sitemap>');
    if ($isSitemapIndex) {
        // Fetch the first child sitemap on this site and get page URLs from it
        $childUrl = '';
        foreach (array_slice($locs, 0, MAX_LOCS) as $loc) {
            if (inScope($loc, $scope)) {
                $childUrl = $loc;
                break;
            }
        }
        if ($childUrl === '') {
            return [[], false];
        }
        $r = guardedFetch($childUrl, CAP_SITEMAP, 10, $scope, ACCEPT_XML);
        if (!$r['ok']) {
            return [[], false];
        }
        $locs = extractLocsFromXml($r['body']);
    }

    $capped = count($locs) > MAX_LOCS || $r['truncated'];
    $urls   = array_filter(array_slice($locs, 0, MAX_LOCS), fn(string $u) => inScope($u, $scope));
    return [array_values(array_unique($urls)), $capped];
}

// ─── IP rate limiting ─────────────────────────────────────────────────────────

function ensureDataDir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    // Ensure .htaccess blocks direct HTTP access to data/
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }
}

/** Rate-limit key: the client IPv4, or the /64 an IPv6 client sits in. */
function rateKey(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false || strlen($packed) !== 16) {
        return $ip;
    }
    if (cidrMatch($packed, '::ffff:0:0/96')) {
        return (string) inet_ntop(substr($packed, 12));
    }
    return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
}

/** Replace the content of an open file: rewind, write all of it, cut to its length, flush. */
function overwriteFile($fh, string $content): bool
{
    return rewind($fh)
        && @fwrite($fh, $content) === strlen($content)
        && ftruncate($fh, strlen($content))
        && fflush($fh);
}

/**
 * Sliding-window limit: at most $max hits per $key in $window seconds, stored as
 * { key: [timestamps] } in $logFile. Read, check and write happen under one flock.
 * With $record false it only checks. Returns null when the log cannot be used,
 * including when the new log cannot be encoded or written (the old one is put back).
 *
 * @return array{limited: bool, next?: string}|null
 */
function rateLimit(string $logFile, string $key, int $window, int $max, bool $record): ?array
{
    ensureDataDir(dirname($logFile));
    $fh = @fopen($logFile, 'c+');
    if ($fh === false) {
        error_log("generate.php: cannot open rate-limit log {$logFile}");
        return null;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        error_log("generate.php: cannot lock rate-limit log {$logFile}");
        return null;
    }

    $raw  = (string) stream_get_contents($fh);
    $data = json_decode($raw, true);
    $data = is_array($data) ? $data : [];
    $now  = time();

    // Keep only hits inside the window (older logs stored one timestamp per key)
    foreach ($data as $k => $hits) {
        $hits = array_values(array_filter(array_map('intval', (array) $hits), fn(int $t) => $now - $t < $window));
        if ($hits) {
            $data[$k] = $hits;
        } else {
            unset($data[$k]);
        }
    }

    $hits    = $data[$key] ?? [];
    $limited = count($hits) >= $max;
    if ($record) {
        if (!$limited) {
            $hits[]     = $now;
            $data[$key] = $hits;
        }
        // Encode before touching the file, and overwrite in place rather than truncate first:
        // a failed write (disk full) puts the old log back and fails closed instead of wiping it
        $json = json_encode($data);
        if ($json === false || !overwriteFile($fh, $json)) {
            if ($json !== false) {
                overwriteFile($fh, $raw);
            }
            flock($fh, LOCK_UN);
            fclose($fh);
            error_log("generate.php: cannot write rate-limit log {$logFile}");
            return null;
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($limited) {
        sort($hits);
        return ['limited' => true, 'next' => date('c', $hits[count($hits) - $max] + $window)];
    }
    return ['limited' => false];
}

// ─── Parallel page fetch ──────────────────────────────────────────────────────

/** @return array<string, array{html:string,type:string}> keyed by final URL */
function fetchPagesParallel(array $urls, array $scope, int $timeout = 8): array
{
    $opt   = ['cap' => CAP_PAGE, 'timeout' => $timeout, 'accept' => ACCEPT_HTML, 'scope' => $scope];
    $pages = [];
    foreach (fetchMany($urls, $opt) as $r) {
        if ($r['ok'] && $r['status'] === 200 && str_contains(strtolower($r['type']), 'html') && !isset($pages[$r['url']])) {
            $pages[$r['url']] = ['html' => $r['body'], 'type' => $r['type']];
        }
    }
    return $pages;
}

/**
 * True when the response is a served llms.txt: 200, not an HTML page, and Markdown that
 * opens with a heading (# Site name), so soft 404s in HTML, JSON or plain text don't count.
 */
function servesLlmsTxt(array $r): bool
{
    if (!$r['ok'] || $r['status'] !== 200 || str_contains(strtolower($r['type']), 'html')) {
        return false;
    }
    $text = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $r['body']) ?? '');
    return str_starts_with($text, '#');
}

// ─── Metadata extraction ──────────────────────────────────────────────────────

/** Decode a page to valid UTF-8, using the charset from the Content-Type header or a <meta> tag. */
function htmlToUtf8(string $html, string $contentType = ''): string
{
    $charset = '';
    if (preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = $m[1];
    }
    if ($charset !== '' && !in_array(strtolower($charset), ['utf-8', 'utf8'], true)) {
        try {
            $converted = mb_convert_encoding($html, 'UTF-8', $charset);
            if (is_string($converted)) {
                $html = $converted;
            }
        } catch (ValueError) {
            // Unknown charset: treat the bytes as UTF-8 below
        }
    }
    return mb_scrub($html, 'UTF-8');
}

function extractMeta(string $html, string $url, string $contentType = ''): array
{
    // Non-ASCII characters become numeric entities, so libxml reads the text correctly
    // whatever charset it guesses (replaces the 'HTML-ENTITIES' conversion, deprecated in PHP 8.2)
    $ascii = mb_encode_numericentity(htmlToUtf8($html, $contentType), [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($ascii !== '' ? $ascii : '<html></html>');
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
    ], JSON_INVALID_UTF8_SUBSTITUTE);

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
        $page['sum'] = mb_substr($summary, 0, 300);
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

// ─── Mode A: discovery ────────────────────────────────────────────────────────

/**
 * Find the site's sitemap, or read the one given, and count its pages on this site.
 * The caller has already counted the request against the discovery limit.
 *
 * @return array{0: array, 1: int} [response payload, HTTP status]
 */
function discoverPages(string $inputUrl, string $sitemapUrl): array
{
    $target = prepareRequest($inputUrl, null);
    if (!$target['ok']) {
        return [['error' => 'refused_url', 'field' => 'url', 'reason' => $target['error']], 400];
    }
    $scope = scopeHosts($target['host']);

    if ($sitemapUrl !== '') {
        $sitemap = prepareRequest($sitemapUrl, $scope);
        if (!$sitemap['ok']) {
            return [['error' => 'refused_url', 'field' => 'sitemap_url', 'reason' => $sitemap['error']], 400];
        }
        $found = $sitemapUrl;
    } else {
        $found = discoverSitemap($target['origin'], $scope);
        if ($found === false) {
            return [['error' => 'sitemap_not_found'], 200];
        }
    }
    [$urls, $capped] = getUrlsFromSitemap($found, $scope);

    $payload = ['sitemap_url' => $found, 'pages_found' => count($urls)];
    if ($capped) {
        $payload['pages_capped'] = true;
    }
    return [$payload, 200];
}

// ─── Response helper ──────────────────────────────────────────────────────────

function respond(array $payload, int $status = 200, int $flags = 0): never
{
    http_response_code($status);
    echo json_encode($payload, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

if (defined('LLMR_GENERATE_LIB')) {
    return; // loaded by the test harness: functions only
}

set_time_limit(120);

header('Content-Type: application/json');

// No CORS headers: only generate.html on this site may call the endpoint
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(['error' => 'method_not_allowed'], 405);
}
// A request another site makes a visitor's browser send is refused before it costs anything
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? null;
if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site'
    || ($requestOrigin !== null && $requestOrigin !== ALLOWED_ORIGIN)) {
    respond(['error' => 'forbidden_origin'], 403);
}
// JSON only: a form post or a "simple" cross-site request can't send it without a preflight
$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
    respond(['error' => 'unsupported_media_type'], 415);
}

loadEnv(__DIR__ . '/../.env');

$MISTRAL_KEY      = $_ENV['MISTRAL_API_KEY']  ?? '';
$MISTRAL_MODEL    = $_ENV['MISTRAL_MODEL']    ?? 'mistral-small-latest';
$MISTRAL_ENDPOINT = $_ENV['MISTRAL_ENDPOINT'] ?? 'https://api.mistral.ai/v1/chat/completions';
$MISTRAL_TIMEOUT  = (int) ($_ENV['MISTRAL_TIMEOUT'] ?? 20);

$body       = json_decode((string) file_get_contents('php://input'), true);
$body       = is_array($body) ? $body : [];
$inputUrl   = trim(is_string($body['url'] ?? null) ? $body['url'] : '');
$sitemapUrl = trim(is_string($body['sitemap_url'] ?? null) ? $body['sitemap_url'] : '');

// No syntax pre-check here: prepareRequest() is stricter, and accepts IDN and underscore hosts
if ($inputUrl === '') {
    respond(['error' => 'invalid_url'], 400);
}

$clientKey    = rateKey($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$dataDir      = __DIR__ . '/../data';
$discoveryLog = $dataDir . '/discovery_log.json';

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// MODE A — Discovery only (no sitemap_url, or "mode": "discover" to count the pages
// of a given sitemap before spending the day's generation on it)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
if ($sitemapUrl === '' || ($body['mode'] ?? null) === 'discover') {
    // ─── Own limit, counted before any DNS lookup or fetch ──────────────────────
    $rate = rateLimit($discoveryLog, $clientKey, DISCOVERY_WINDOW, DISCOVERY_LIMIT, true);
    if ($rate === null) {
        respond(['error' => 'rate_limit_unavailable'], 503);
    }
    if ($rate['limited']) {
        respond(['error' => 'discovery_rate_limited', 'next_available' => $rate['next']], 429);
    }

    [$payload, $status] = discoverPages($inputUrl, $sitemapUrl);
    respond($payload, $status);
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
// MODE B — Full processing (sitemap_url provided)
// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

// ─── IP rate limit: 1 generation per IP per 24 h ─────────────────────────────
// Check first without recording, so a client that is already limited costs no DNS lookup
$logFile = $dataDir . '/ip_log.json';
$rate    = rateLimit($logFile, $clientKey, GENERATION_WINDOW, GENERATION_LIMIT, false);
if ($rate === null) {
    respond(['error' => 'rate_limit_unavailable'], 503);
}
if ($rate['limited']) {
    respond(['error' => 'rate_limited', 'next_available' => $rate['next']], 429);
}

// Validation looks up names the client picks: count it against the hourly limit first
$rate = rateLimit($discoveryLog, $clientKey, DISCOVERY_WINDOW, DISCOVERY_LIMIT, true);
if ($rate === null) {
    respond(['error' => 'rate_limit_unavailable'], 503);
}
if ($rate['limited']) {
    respond(['error' => 'discovery_rate_limited', 'next_available' => $rate['next']], 429);
}

// Validate all input before the generation is recorded
$target = prepareRequest($inputUrl, null);
if (!$target['ok']) {
    respond(['error' => 'refused_url', 'field' => 'url', 'reason' => $target['error']], 400);
}
$scope   = scopeHosts($target['host']);
$sitemap = prepareRequest($sitemapUrl, $scope);
if (!$sitemap['ok']) {
    respond(['error' => 'refused_url', 'field' => 'sitemap_url', 'reason' => $sitemap['error']], 400);
}

// Record the generation; the check runs again under the lock, so parallel requests can't both pass
$rate = rateLimit($logFile, $clientKey, GENERATION_WINDOW, GENERATION_LIMIT, true);
if ($rate === null) {
    respond(['error' => 'rate_limit_unavailable'], 503);
}
if ($rate['limited']) {
    respond(['error' => 'rate_limited', 'next_available' => $rate['next']], 429);
}

// Get URLs
[$allUrls] = getUrlsFromSitemap($sitemapUrl, $scope);
if (!$allUrls) {
    respond(['error' => 'sitemap_empty']);
}

$wasCapped = count($allUrls) > MAX_PAGES;
if ($wasCapped) {
    shuffle($allUrls);
}
$urls = array_slice($allUrls, 0, MAX_PAGES);

// Parallel fetch
$htmlByUrl = fetchPagesParallel($urls, $scope);
if (!$htmlByUrl) {
    respond(['error' => 'fetch_failed']);
}

// Companion llms.txt (LLMR v2.1 pairing): link it from s.llms_txt when the site serves one
$llmsTxtUrl = $target['origin'] . '/llms.txt';
$hasLlmsTxt = servesLlmsTxt(guardedFetch($llmsTxtUrl, CAP_TEXT, 5, $scope, ACCEPT_TEXT));

// Extract metadata
$pagesData = [];
foreach ($htmlByUrl as $pageUrl => $page) {
    $pagesData[] = ['url' => $pageUrl, 'meta' => extractMeta($page['html'], $pageUrl, $page['type']), 'summary' => ''];
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
$site = ['d' => $target['host']];
if ($siteAuthor !== '') {
    $site['a'] = ['n' => $siteAuthor, 'r' => ''];
}
if ($hasLlmsTxt) {
    $site['llms_txt'] = $llmsTxtUrl;
}

$llmr = [
    'v'  => LLMR_VERSION,
    'ts' => time(),
    's'  => $site,
    'p'  => $pages,
];

// Stats
$pageCount    = count($pages);
$estHtmlBytes = $pageCount * 27500;
$llmrBytes    = strlen((string) json_encode($llmr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
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

respond($response, 200, JSON_PRETTY_PRINT);
