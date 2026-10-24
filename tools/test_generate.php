<?php
/**
 * tools/test_generate.php: tests for config/generate.php (SSRF guard, scope rules,
 * rate limits, LLMR v2.1 output helpers).
 *
 * Run:  wsl -e php8.3 tools/test_generate.php
 *
 * DNS is stubbed (Net::$resolver) and HTTP is scripted (Net::$transport), except in the
 * last two sections: they start `php -S` on 127.0.0.1, drive the real curl transport
 * with an explicit pin, and POST bad input to a copy of the endpoint. Nothing is sent
 * off the machine. CLI only; tools/ is repo-only, don't upload it.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false; // silenced with @ on purpose
    }
    // Any warning, notice or deprecation fails the run
    throw new ErrorException($msg, 0, $no, $file, $line);
});

define('LLMR_GENERATE_LIB', true);
require __DIR__ . '/../config/generate.php';

$MAIN_PID = getmypid();
$passed   = 0;
$failed   = 0;
$skipped  = 0;

function section(string $title): void
{
    echo "\n{$title}\n";
}

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok    {$name}\n";
    } else {
        $failed++;
        echo "  FAIL  {$name}" . ($detail !== '' ? "\n        {$detail}" : '') . "\n";
    }
}

function same(string $name, mixed $expected, mixed $actual): void
{
    $show = fn($v) => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    check($name, $expected === $actual, 'expected ' . $show($expected) . ', got ' . $show($actual));
}

function skip(string $name, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  skip  {$name} ({$why})\n";
}

/** The refusal reason of a prepareRequest() result, or 'ACCEPTED'. */
function reason(array $r): string
{
    return $r['ok'] ? 'ACCEPTED' : $r['error'];
}

// ─── Stubs ────────────────────────────────────────────────────────────────────

const STUB_DNS = [
    'site.example.com'       => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
    'www.site.example.com'   => ['93.184.216.34'],
    'v6only.example.com'     => ['2606:4700::6810:84e5'],
    'mappedpub.example.com'  => ['::ffff:93.184.216.34'],
    'other.example.net'      => ['93.184.216.35'],
    'loopback.example.com'   => ['127.0.0.1'],
    'private10.example.com'  => ['10.0.0.5'],
    'private172.example.com' => ['172.20.1.1'],
    'private192.example.com' => ['192.168.1.10'],
    'metadata.example.com'   => ['169.254.169.254'],
    'cgnat.example.com'      => ['100.100.100.200'],
    'zero.example.com'       => ['0.0.0.0'],
    'mcast.example.com'      => ['239.255.255.250'],
    'mixed4.example.com'     => ['93.184.216.34', '192.168.0.1'],
    'mixed6.example.com'     => ['93.184.216.34', '::1'],
    'ula.example.com'        => ['93.184.216.34', 'fd00:ec2::254'],
    'linklocal6.example.com' => ['fe80::1'],
    'mapped.example.com'     => ['::ffff:127.0.0.1'],
    'nat64.example.com'      => ['64:ff9b::a9fe:a9fe'],     // wraps 169.254.169.254
    'sixtofour.example.com'  => ['2002:c0a8:101::1'],       // wraps 192.168.1.1
    'teredo.example.com'     => ['2001:0:4136:e378:8000:63bf:3fff:fdd2'],
    'nxdomain.example.com'   => false,
];

$dnsCalls    = [];
$httpLog     = [];
$httpBatches = [];

function useStubDns(): void
{
    Net::$dnsCache  = [];
    $GLOBALS['dnsCalls'] = [];
    Net::$resolver = function (string $host): array|false {
        $GLOBALS['dnsCalls'][] = $host;
        return STUB_DNS[$host] ?? false;
    };
}

/** Scripted HTTP: URL => response. Logs every request that reached the "network". */
function useStubHttp(array $routes): void
{
    $GLOBALS['httpLog']     = [];
    $GLOBALS['httpBatches'] = [];
    Net::$transport = function (array $requests) use ($routes): array {
        $GLOBALS['httpBatches'][] = count($requests);
        $out = [];
        foreach ($requests as $key => $req) {
            $GLOBALS['httpLog'][] = $req;
            $out[$key] = ($routes[$req['url']] ?? ['status' => 404])
                       + ['status' => 200, 'type' => 'text/html', 'location' => '', 'body' => '', 'truncated' => false, 'error' => ''];
        }
        return $out;
    };
}

function redirect(string $to, int $status = 302): array
{
    return ['status' => $status, 'location' => $to];
}

function page(string $body, string $type = 'text/html; charset=utf-8'): array
{
    return ['status' => 200, 'type' => $type, 'body' => $body];
}

function loc(string $url, string $wrap = 'url'): string
{
    return "<{$wrap}><loc>{$url}</loc></{$wrap}>";
}

/** Every request that reached the transport went to a public address on port 80/443. */
function networkStayedPublic(): bool
{
    foreach ($GLOBALS['httpLog'] as $req) {
        if (!isPublicIp($req['ip']) || !in_array($req['port'], [80, 443], true)
            || !preg_match('#^https?://#', $req['url'])) {
            return false;
        }
    }
    return true;
}

function requestedHosts(): array
{
    return array_values(array_unique(array_map(fn($r) => $r['host'], $GLOBALS['httpLog'])));
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("{$dir}/{$f}") ? rrmdir("{$dir}/{$f}") : unlink("{$dir}/{$f}");
        }
    }
    rmdir($dir);
}

$TMP = sys_get_temp_dir() . '/llmr-test-' . bin2hex(random_bytes(4));
mkdir($TMP, 0700, true);
register_shutdown_function(function () use ($TMP, $MAIN_PID): void {
    if (getmypid() === $MAIN_PID) {
        rrmdir($TMP);
    }
});

// ─── 1. Address classification ────────────────────────────────────────────────

section('1. isPublicIp');

$publicIps = ['8.8.8.8', '93.184.216.34', '1.1.1.1', '100.63.255.255', '100.128.0.0', '172.15.255.255',
              '172.32.0.0', '192.169.0.1', '2606:4700::1', '2a00:1450:4007:80e::200e', '::ffff:8.8.8.8',
              '64:ff9b::808:808', '2002:808:808::1'];
foreach ($publicIps as $ip) {
    same("public  {$ip}", true, isPublicIp($ip));
}

$blockedIps = [
    '127.0.0.1', '127.255.255.254', '10.0.0.1', '172.16.0.1', '172.31.255.255', '192.168.1.1',
    '169.254.169.254', '100.64.0.1', '100.127.255.255', '100.100.100.200', '0.0.0.0', '0.1.2.3',
    '224.0.0.1', '239.255.255.250', '240.0.0.1', '255.255.255.255', '192.0.0.8', '192.0.2.1',
    '198.18.0.1', '198.19.255.255', '198.51.100.1', '203.0.113.1', '192.88.99.1',
    '::', '::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::ffff:169.254.169.254', '::127.0.0.1',
    '64:ff9b::7f00:1', '64:ff9b::a00:1', '64:ff9b:1::1', 'fc00::1', 'fd00:ec2::254', 'fe80::1',
    'fec0::1', 'ff02::1', '100::1', '2001:db8::1', '2001::1', '2001:2::1', '2002:7f00:1::1',
    '2002:c0a8:101::1', '3fff::1', 'not-an-ip', '', '1.2.3', '256.1.1.1',
];
foreach ($blockedIps as $ip) {
    same("blocked {$ip}", false, isPublicIp($ip));
}

// ─── 2. URL guard without DNS ─────────────────────────────────────────────────

section('2. prepareRequest: schemes, ports, hosts, IP literals (no DNS)');

useStubDns();
Net::$transport = null;

$refusals = [
    // schemes
    ['file:///etc/passwd', 'bad_scheme'],
    ['ftp://site.example.com/x', 'bad_scheme'],
    ['gopher://site.example.com:70/_GET%20/', 'bad_scheme'],
    ['dict://site.example.com:11211/stat', 'bad_scheme'],
    ['ldap://site.example.com/', 'bad_scheme'],
    ['sftp://site.example.com/', 'bad_scheme'],
    ['php://filter/resource=/etc/passwd', 'bad_scheme'],
    ['data:text/plain,hello', 'bad_scheme'],
    ['javascript:alert(1)', 'bad_scheme'],
    ['//site.example.com/x', 'bad_url'],
    ['site.example.com/x', 'bad_url'],
    ['', 'bad_url'],
    // ports
    ['http://site.example.com:8080/', 'bad_port'],
    ['https://site.example.com:8443/', 'bad_port'],
    ['http://site.example.com:22/', 'bad_port'],
    ['http://site.example.com:6379/', 'bad_port'],
    ['http://site.example.com:0/', 'bad_port'],
    ['http://93.184.216.34:25/', 'bad_port'],
    ['http://site.example.com:65536/', 'bad_url'],
    // credentials and parser confusion
    ['http://user:pass@site.example.com/', 'credentials'],
    ['http://site.example.com@127.0.0.1/', 'credentials'],
    ['http://site.example.com\\@127.0.0.1/', 'bad_url'],
    ['http://site.example.com /', 'bad_url'],
    ["http://site.example.com/\r\nHost: evil", 'bad_url'],
    ["http://site.example.com/\0", 'bad_url'],
    ['http://127.0.0.1#@site.example.com/', 'private_address'],
    // IPv4 literals and the forms curl reads as IPv4
    ['http://127.0.0.1/', 'private_address'],
    ['http://127.0.0.1.:80/admin', 'private_address'],
    ['http://10.0.0.1/', 'private_address'],
    ['http://169.254.169.254/latest/meta-data/', 'private_address'],
    ['http://100.100.100.200/latest/meta-data/', 'private_address'],
    ['http://0.0.0.0/', 'private_address'],
    ['http://2130706433/', 'bad_host'],          // decimal 127.0.0.1
    ['http://017700000001/', 'bad_host'],        // octal
    ['http://0x7f000001/', 'bad_host'],          // hex
    ['http://0177.0.0.1/', 'bad_host'],          // octal octet
    ['http://0x7f.0.0.1/', 'bad_host'],          // hex octet
    ['http://127.0.0.0x1/', 'bad_host'],
    ['http://127.1/', 'bad_host'],               // short form
    ['http://127.0.1/', 'bad_host'],
    ['http://0/', 'bad_host'],
    ['http://1.2.3.4.5/', 'bad_host'],
    // IPv6 literals
    ['http://[::1]/', 'private_address'],
    ['http://[::]/', 'private_address'],
    ['http://[::ffff:127.0.0.1]/', 'private_address'],
    ['http://[::ffff:7f00:1]/', 'private_address'],
    ['http://[0:0:0:0:0:ffff:7f00:0001]/', 'private_address'],
    ['http://[::ffff:169.254.169.254]/', 'private_address'],
    ['http://[64:ff9b::127.0.0.1]/', 'private_address'],
    ['http://[2002:7f00:1::1]/', 'private_address'],
    ['http://[fe80::1]/', 'private_address'],
    ['http://[fd00:ec2::254]/', 'private_address'],
    ['http://[fe80::1%25eth0]/', 'bad_host'],
    ['http://[::1/', 'bad_host'],
    // names that are never public
    ['http://localhost/', 'bad_host'],
    ['http://LOCALHOST./', 'bad_host'],
    ['http://foo.localhost/', 'bad_host'],
    ['http://intranet/', 'bad_host'],
    ['http://printer.local/', 'bad_host'],
    ['http://metadata.google.internal/computeMetadata/v1/', 'bad_host'],
    ['http://router.home.arpa/', 'bad_host'],
    ['http://1.0.0.127.in-addr.arpa/', 'bad_host'],
    ['http://bad-.example.com/', 'bad_host'],
    ['http://%31%32%37.0.0.1/', 'bad_host'],
];
foreach ($refusals as [$url, $reason]) {
    $r = prepareRequest($url, null);
    same('refuse ' . json_encode($url, JSON_UNESCAPED_SLASHES) . " ({$reason})", $reason, reason($r));
}
same('no DNS lookup for refused literals/schemes/ports', [], $GLOBALS['dnsCalls']);

// ─── 3. URL guard with DNS ────────────────────────────────────────────────────

section('3. prepareRequest: every A/AAAA record must be public; pin and rebuild');

useStubDns();
$dnsRefusals = [
    'loopback', 'private10', 'private172', 'private192', 'metadata', 'cgnat', 'zero', 'mcast',
    'mixed4', 'mixed6', 'ula', 'linklocal6', 'mapped', 'nat64', 'sixtofour', 'teredo',
];
foreach ($dnsRefusals as $name) {
    $r = prepareRequest("https://{$name}.example.com/", null);
    same("refuse {$name}.example.com (" . implode(', ', STUB_DNS["{$name}.example.com"]) . ')', 'private_address', reason($r));
}
same('refuse NXDOMAIN', 'dns_failed', reason(prepareRequest('https://nxdomain.example.com/', null)));
same('refuse unknown host', 'dns_failed', reason(prepareRequest('https://unknown.example.com/', null)));

$r = prepareRequest('https://site.example.com/', null);
same('accept site.example.com', true, $r['ok']);
same('pin prefers the IPv4 record', '93.184.216.34', $r['ip']);
same('port 443', 443, $r['port']);

$r = prepareRequest('HTTPS://Site.Example.COM./a/b?q=1#frag', null);
same('rebuilt URL: lower-case host, no trailing dot, no fragment', 'https://site.example.com/a/b?q=1', $r['url']);
same('origin', 'https://site.example.com', $r['origin']);

$r = prepareRequest('https://site.example.com#@127.0.0.1/', null);
same('fragment trick: host stays site.example.com', 'https://site.example.com/', $r['url']);

same('explicit :80 dropped', 'http://site.example.com/x', prepareRequest('http://site.example.com:80/x', null)['url']);
same('explicit :443 dropped', 'https://site.example.com/x', prepareRequest('https://site.example.com:443/x', null)['url']);
same('http on 443 allowed and kept', 'http://site.example.com:443/', prepareRequest('http://site.example.com:443/', null)['url']);
same('non-ASCII path percent-encoded', 'https://site.example.com/caf%C3%A9', prepareRequest("https://site.example.com/caf\u{e9}", null)['url']);
same('IPv6-only host pins its AAAA', '2606:4700::6810:84e5', prepareRequest('https://v6only.example.com/', null)['ip']);
same('IPv4-mapped public AAAA pins the IPv4', '93.184.216.34', prepareRequest('https://mappedpub.example.com/', null)['ip']);

$GLOBALS['dnsCalls'] = [];
$r = prepareRequest('http://93.184.216.34/', null);
same('public IPv4 literal accepted', '93.184.216.34', $r['ip']);
$r = prepareRequest('http://[2606:4700::1]/x', null);
same('public IPv6 literal accepted, bracketed URL', 'http://[2606:4700::1]/x', $r['url']);
same('IP literals need no DNS', [], $GLOBALS['dnsCalls']);

same('IDN host converted to punycode', ['xn--bcher-kva.example.com', 'name'], normalizeHost("b\u{fc}cher.example.com"));
prepareRequest("https://b\u{fc}cher.example.com/", null);
same('IDN host resolved in ASCII form', ['xn--bcher-kva.example.com'], $GLOBALS['dnsCalls']);

$GLOBALS['dnsCalls'] = [];
prepareRequest('https://site.example.com/a', null);
prepareRequest('https://site.example.com/b', null);
same('one DNS lookup per host per request', [], $GLOBALS['dnsCalls']);

// ─── 4. Scope ─────────────────────────────────────────────────────────────────

section('4. Scope: submitted host plus its www./apex twin');

same('scope of apex', ['site.example.com', 'www.site.example.com'], scopeHosts('site.example.com'));
same('scope of www', ['www.site.example.com', 'site.example.com'], scopeHosts('www.site.example.com'));
same('scope of an IP', ['93.184.216.34'], scopeHosts('93.184.216.34'));

$scope = scopeHosts('site.example.com');
$scopeCases = [
    ['https://site.example.com/sitemap.xml', true],
    ['http://site.example.com/sitemap.xml', true],
    ['https://WWW.Site.Example.com/sitemap.xml', true],
    ['https://other.example.net/sitemap.xml', false],
    ['https://blog.site.example.com/sitemap.xml', false],
    ['https://site.example.com.evil.example.net/sitemap.xml', false],
    ['https://evilsite.example.com/', false],
    ['http://127.0.0.1/sitemap.xml', false],
    ['ftp://site.example.com/sitemap.xml', false],
    ['https://site.example.com@other.example.net/', false],
];
foreach ($scopeCases as [$url, $expected]) {
    same('inScope ' . $url, $expected, inScope($url, $scope));
}
same('off-origin sitemap refused', 'off_origin', reason(prepareRequest('https://other.example.net/sitemap.xml', $scope)));
same('sub-domain sitemap refused', 'off_origin', reason(prepareRequest('https://blog.site.example.com/sitemap.xml', $scope)));
same('www twin sitemap accepted', true, prepareRequest('https://www.site.example.com/sitemap.xml', $scope)['ok']);

// ─── 5. Redirect resolution ───────────────────────────────────────────────────

section('5. resolveUrl');

$resolveCases = [
    ['https://a.example.com/x/y', '/z', 'https://a.example.com/z'],
    ['https://a.example.com/x/y', 'z', 'https://a.example.com/x/z'],
    ['https://a.example.com/x/y', '../z', 'https://a.example.com/z'],
    ['https://a.example.com/x/y/', './z/../w', 'https://a.example.com/x/y/w'],
    ['https://a.example.com/x/y', '../../../../z', 'https://a.example.com/z'],
    ['https://a.example.com/x/y', '..', 'https://a.example.com/'],
    ['https://a.example.com/x/y', '?p=1', 'https://a.example.com/x/y?p=1'],
    ['https://a.example.com/x/y', 'z?p=1#frag', 'https://a.example.com/x/z?p=1'],
    ['https://a.example.com/x/y', '//b.example.net/q', 'https://b.example.net/q'],
    ['http://a.example.com/x', 'https://a.example.com/y', 'https://a.example.com/y'],
    ['https://a.example.com/x', 'http://127.0.0.1/', 'http://127.0.0.1/'],
    ['https://a.example.com/x', 'FTP://a.example.com/f', 'FTP://a.example.com/f'],
    ['http://[2606:4700::1]/a/b', 'c', 'http://[2606:4700::1]/a/c'],
    ['http://a.example.com:443/a', '/b', 'http://a.example.com:443/b'],
];
foreach ($resolveCases as [$base, $ref, $expected]) {
    same("{$base} + {$ref}", $expected, resolveUrl($base, $ref));
}

// ─── 6. Redirects through the guard ───────────────────────────────────────────

section('6. Redirects: followed by hand, every hop checked again');

useStubDns();
$redirectRoutes = [
    'https://site.example.com/to-loopback'      => redirect('http://127.0.0.1/admin'),
    'https://site.example.com/to-metadata'      => redirect('http://169.254.169.254/latest/meta-data/'),
    'https://site.example.com/to-mapped'        => redirect('http://[::ffff:127.0.0.1]/'),
    'https://site.example.com/to-decimal'       => redirect('http://2130706433/'),
    'https://site.example.com/to-localhost'     => redirect('http://localhost:8080/'),
    'https://site.example.com/to-ftp'           => redirect('ftp://site.example.com/file'),
    'https://site.example.com/to-file'          => redirect('file:///etc/passwd'),
    'https://site.example.com/to-gopher'        => redirect('gopher://site.example.com:70/_x'),
    'https://site.example.com/to-port'          => redirect('https://site.example.com:8443/'),
    'https://site.example.com/to-creds'         => redirect('https://user:pw@site.example.com/'),
    'https://site.example.com/to-other'         => redirect('https://other.example.net/'),
    'https://site.example.com/to-protorel'      => redirect('//other.example.net/x'),
    'https://site.example.com/to-backslash'     => redirect('/\\other.example.net/'),
    'https://site.example.com/to-loopback-name' => redirect('https://loopback.example.com/'),
    'https://site.example.com/to-www'           => redirect('https://www.site.example.com/landing', 301),
    'https://www.site.example.com/landing'      => page('<title>www</title>'),
    'https://site.example.com/rel'              => redirect('next/../final?x=1', 307),
    'https://site.example.com/final?x=1'        => page('<title>final</title>'),
    'https://site.example.com/hop1'             => redirect('/hop2'),
    'https://site.example.com/hop2'             => redirect('/hop3', 301),
    'https://site.example.com/hop3'             => redirect('/hop4', 308),
    'https://site.example.com/hop4'             => page('<title>after 3 redirects</title>'),
    'https://site.example.com/loop1'            => redirect('/loop2'),
    'https://site.example.com/loop2'            => redirect('/loop3'),
    'https://site.example.com/loop3'            => redirect('/loop4'),
    'https://site.example.com/loop4'            => redirect('/loop5'),
    'https://site.example.com/loop5'            => page('<title>too far</title>'),
    'https://site.example.com/a'                => page('<title>a</title>'),
    'https://site.example.com/no-location'      => ['status' => 302],
];

$redirectCases = [
    // [path, scope, expected error ('' = ok), requests that reach the network]
    ['/to-loopback', $scope, 'off_origin', 1],
    ['/to-loopback', null, 'private_address', 1],
    ['/to-metadata', null, 'private_address', 1],
    ['/to-mapped', null, 'private_address', 1],
    ['/to-decimal', null, 'bad_host', 1],
    ['/to-localhost', null, 'bad_host', 1],
    ['/to-ftp', $scope, 'bad_scheme', 1],
    ['/to-file', $scope, 'bad_scheme', 1],
    ['/to-gopher', $scope, 'bad_scheme', 1],
    ['/to-port', $scope, 'bad_port', 1],
    ['/to-creds', $scope, 'credentials', 1],
    ['/to-other', $scope, 'off_origin', 1],
    ['/to-protorel', $scope, 'off_origin', 1],
    ['/to-backslash', $scope, 'bad_url', 1],
    ['/to-loopback-name', null, 'private_address', 1],
    ['/to-www', $scope, '', 2],
    ['/rel', $scope, '', 2],
    ['/hop1', $scope, '', 4],
    ['/loop1', $scope, 'too_many_redirects', 4],
];
foreach ($redirectCases as [$path, $caseScope, $expectedError, $expectedRequests]) {
    useStubHttp($redirectRoutes);
    $r     = guardedFetch('https://site.example.com' . $path, 1024, 5, $caseScope, ACCEPT_HTML);
    $label = $path . ($caseScope === null ? ' (no scope)' : '');
    same("{$label}: " . ($expectedError ?: 'followed'), $expectedError, $r['error']);
    same("{$label}: requests sent", $expectedRequests, count($GLOBALS['httpLog']));
    check("{$label}: network stayed public, ports 80/443", networkStayedPublic());
}

useStubHttp($redirectRoutes);
$r = guardedFetch('https://site.example.com/rel', 1024, 5, $scope, ACCEPT_HTML);
same('relative Location resolved against the current URL', 'https://site.example.com/final?x=1', $r['url']);
same('body of the final hop', '<title>final</title>', $r['body']);
$r = guardedFetch('https://site.example.com/no-location', 1024, 5, $scope, ACCEPT_HTML);
same('3xx without Location is a failed fetch, not a crash', [false, 302], [$r['ok'], $r['status']]);

// Parallel page fetch uses the same guard
useStubHttp($redirectRoutes);
$pages = fetchPagesParallel([
    'https://site.example.com/rel',
    'https://site.example.com/to-loopback',
    'https://site.example.com/a',
    'https://other.example.net/b',
    'http://127.0.0.1:8080/c',
], $scope);
same('parallel: only the two safe pages come back', ['https://site.example.com/final?x=1', 'https://site.example.com/a'], array_keys($pages));
same('parallel: first round is one batch of 3, off-origin URLs never sent', [3, 1], $GLOBALS['httpBatches']);
same('parallel: only scope hosts requested', ['site.example.com'], requestedHosts());
check('parallel: network stayed public', networkStayedPublic());

// ─── 7. DNS rebinding ─────────────────────────────────────────────────────────

section('7. DNS rebinding: one lookup, every connection pinned to the checked address');

Net::$dnsCache = [];
$lookups = 0;
Net::$resolver = function (string $host) use (&$lookups): array {
    $lookups++;
    return $lookups === 1 ? ['93.184.216.34'] : ['127.0.0.1']; // public first, then rebinds
};
useStubHttp([
    'https://rebind.example.org/a' => redirect('/b'),
    'https://rebind.example.org/b' => page('b'),
    'https://rebind.example.org/c' => page('c'),
]);
$rebindScope = scopeHosts('rebind.example.org');
fetchMany(['https://rebind.example.org/a', 'https://rebind.example.org/c'], ['cap' => 1024, 'timeout' => 5, 'accept' => ACCEPT_HTML, 'scope' => $rebindScope]);
guardedFetch('https://rebind.example.org/c', 1024, 5, $rebindScope, ACCEPT_HTML);
same('resolver called once', 1, $lookups);
same('every request pinned to 93.184.216.34', ['93.184.216.34'], array_values(array_unique(array_map(fn($r) => $r['ip'], $GLOBALS['httpLog']))));
same('requests sent', 4, count($GLOBALS['httpLog']));

// ─── 8. Sitemaps ──────────────────────────────────────────────────────────────

section('8. Sitemaps: scope filter, no DNS per <loc>, caps');

useStubDns();
$urlset = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . loc('https://site.example.com/a')
        . loc('https://www.site.example.com/b')
        . loc('http://site.example.com/c')
        . loc('https://other.example.net/x')
        . loc('http://127.0.0.1/admin')
        . loc('http://169.254.169.254/latest/meta-data/')
        . loc('https://site.example.com.evil.example.net/')
        . loc('https://blog.site.example.com/post')
        . loc('https://site.example.com:8080/port')
        . loc('ftp://site.example.com/file')
        . loc('https://site.example.com/a')
        . loc('https://site.example.com/q?a=1&amp;b=2')
        . '</urlset>';
$index = '<?xml version="1.0"?><sitemapindex>'
       . loc('https://other.example.net/sitemap.xml', 'sitemap')
       . loc('http://127.0.0.1/sitemap.xml', 'sitemap')
       . loc('https://www.site.example.com/posts.xml', 'sitemap')
       . '</sitemapindex>';
$posts = '<urlset>' . loc('https://site.example.com/p1') . loc('https://other.example.net/p2') . '</urlset>';
$many  = '<urlset>' . implode('', array_map(fn($i) => loc("https://site.example.com/p{$i}"), range(1, MAX_LOCS + 50))) . '</urlset>';

useStubHttp([
    'https://site.example.com/sitemap.xml'       => page($urlset, 'application/xml'),
    'https://site.example.com/index.xml'         => page($index, 'application/xml'),
    'https://www.site.example.com/posts.xml'     => page($posts, 'application/xml'),
    'https://site.example.com/foreign-index.xml' => page('<sitemapindex>' . loc('https://other.example.net/s.xml', 'sitemap') . '</sitemapindex>', 'application/xml'),
    'https://site.example.com/many.xml'          => page($many, 'application/xml'),
    'https://site.example.com/cut.xml'           => ['status' => 200, 'type' => 'application/xml', 'body' => substr($urlset, 0, 300), 'truncated' => true],
]);

[$urls, $capped] = getUrlsFromSitemap('https://site.example.com/sitemap.xml', $scope);
same('urlset: only http(s) URLs on the site or its twin, de-duplicated', [
    'https://site.example.com/a',
    'https://www.site.example.com/b',
    'http://site.example.com/c',
    'https://site.example.com/q?a=1&b=2',
], $urls);
same('urlset: not capped', false, $capped);
same('urlset: DNS only for the site itself', ['site.example.com'], $GLOBALS['dnsCalls']);

[$urls] = getUrlsFromSitemap('https://site.example.com/index.xml', $scope);
same('index: first child on this site is read', ['https://site.example.com/p1'], $urls);
same('index: off-origin children never requested', ['site.example.com', 'www.site.example.com'], requestedHosts());

same('index with only foreign children: nothing', [[], false], getUrlsFromSitemap('https://site.example.com/foreign-index.xml', $scope));

$GLOBALS['dnsCalls'] = [];
[$urls, $capped] = getUrlsFromSitemap('https://site.example.com/many.xml', $scope);
same('MAX_LOCS cap', MAX_LOCS, count($urls));
same('MAX_LOCS: reported as capped', true, $capped);
same('MAX_LOCS: no extra DNS lookups', [], $GLOBALS['dnsCalls']);

[$urls, $capped] = getUrlsFromSitemap('https://site.example.com/cut.xml', $scope);
same('size-capped sitemap: complete <loc> entries kept, reported as capped', [true, true], [count($urls) > 0, $capped]);

same('off-origin sitemap URL is refused before any request', [[], false], getUrlsFromSitemap('https://other.example.net/sitemap.xml', $scope));

useStubHttp([
    'https://site.example.com/robots.txt' => page("User-agent: *\nSitemap: https://other.example.net/sitemap.xml\nSitemap: http://127.0.0.1/sitemap.xml\nsitemap: https://www.site.example.com/real-sitemap.xml\n", 'text/plain'),
]);
same('robots.txt: first Sitemap on this site wins', 'https://www.site.example.com/real-sitemap.xml', discoverSitemap('https://site.example.com', $scope));
useStubHttp([
    'https://site.example.com/robots.txt' => page("Sitemap: https://other.example.net/sitemap.xml\nSitemap: http://10.0.0.1/sitemap.xml\n", 'text/plain'),
]);
same('robots.txt: off-origin Sitemap lines ignored', false, discoverSitemap('https://site.example.com', $scope));
same('robots.txt: candidates and robots fetched from the site only', ['site.example.com'], requestedHosts());
useStubHttp(['https://site.example.com/sitemap.xml' => page($urlset, 'application/xml')]);
same('sitemap.xml found first', 'https://site.example.com/sitemap.xml', discoverSitemap('https://site.example.com', $scope));

// ─── 9. llms.txt pairing ──────────────────────────────────────────────────────

section('9. llms.txt detection');

$fetched = fn(array $over) => $over + ['ok' => true, 'status' => 200, 'type' => 'text/plain', 'body' => "# Site\n> About\n", 'url' => '', 'truncated' => false, 'error' => ''];
same('200 text/plain', true, servesLlmsTxt($fetched([])));
same('200 text/markdown', true, servesLlmsTxt($fetched(['type' => 'text/markdown; charset=utf-8'])));
same('200 with BOM', true, servesLlmsTxt($fetched(['body' => "\xEF\xBB\xBF# Site\n"])));
same('no Content-Type', true, servesLlmsTxt($fetched(['type' => ''])));
same('200 text/html (soft 404)', false, servesLlmsTxt($fetched(['type' => 'text/html; charset=UTF-8', 'body' => '<!doctype html>'])));
same('200 text/plain but HTML body', false, servesLlmsTxt($fetched(['body' => "\n<!DOCTYPE html><html>"])));
same('200 empty', false, servesLlmsTxt($fetched(['body' => " \n"])));
same('404', false, servesLlmsTxt($fetched(['ok' => false, 'status' => 404])));

useStubDns();
useStubHttp([
    'https://site.example.com/llms.txt'     => redirect('https://www.site.example.com/llms.txt', 301),
    'https://www.site.example.com/llms.txt' => page("# Site\n", 'text/plain; charset=utf-8'),
    'https://site.example.com/evil.txt'     => redirect('http://127.0.0.1/llms.txt'),
]);
same('llms.txt behind a redirect to the www twin', true, servesLlmsTxt(guardedFetch('https://site.example.com/llms.txt', CAP_TEXT, 5, $scope, ACCEPT_TEXT)));
same('llms.txt redirecting to loopback', false, servesLlmsTxt(guardedFetch('https://site.example.com/evil.txt', CAP_TEXT, 5, $scope, ACCEPT_TEXT)));

// ─── 10. Rate limits ──────────────────────────────────────────────────────────

section('10. Rate limits');

$log = "{$TMP}/data/discovery_log.json";
$allowed = 0;
for ($i = 0; $i < DISCOVERY_LIMIT; $i++) {
    $r = rateLimit($log, '198.51.100.7', DISCOVERY_WINDOW, DISCOVERY_LIMIT, true);
    $allowed += $r['limited'] ? 0 : 1;
}
same('discovery: ' . DISCOVERY_LIMIT . ' allowed per hour', DISCOVERY_LIMIT, $allowed);
$r = rateLimit($log, '198.51.100.7', DISCOVERY_WINDOW, DISCOVERY_LIMIT, true);
same('discovery: next one limited', true, $r['limited']);
check('discovery: next_available about an hour away', abs(strtotime($r['next']) - (time() + DISCOVERY_WINDOW)) <= 5, $r['next']);
same('discovery: other clients unaffected', false, rateLimit($log, '198.51.100.8', DISCOVERY_WINDOW, DISCOVERY_LIMIT, true)['limited']);
same('data/.htaccess created', true, is_file("{$TMP}/data/.htaccess"));
same('data/.htaccess denies access', "Order deny,allow\nDeny from all\n", file_get_contents("{$TMP}/data/.htaccess"));

$genLog = "{$TMP}/data/ip_log.json";
same('generation: read-only check passes', false, rateLimit($genLog, '203.0.113.9', GENERATION_WINDOW, GENERATION_LIMIT, false)['limited']);
same('generation: read-only check records nothing', false, rateLimit($genLog, '203.0.113.9', GENERATION_WINDOW, GENERATION_LIMIT, false)['limited']);
same('generation: first recorded', false, rateLimit($genLog, '203.0.113.9', GENERATION_WINDOW, GENERATION_LIMIT, true)['limited']);
same('generation: second refused', true, rateLimit($genLog, '203.0.113.9', GENERATION_WINDOW, GENERATION_LIMIT, true)['limited']);

$now = time();
file_put_contents($genLog, json_encode(['203.0.113.10' => $now - 100, '203.0.113.11' => $now - 90000, '203.0.113.13' => $now - 90000]));
$r = rateLimit($genLog, '203.0.113.10', GENERATION_WINDOW, GENERATION_LIMIT, false);
same('old log format {ip: ts} still read', true, $r['limited']);
check('old log format: next_available = ts + 24 h', abs(strtotime($r['next']) - ($now - 100 + GENERATION_WINDOW)) <= 1, $r['next']);
same('expired entry not limited', false, rateLimit($genLog, '203.0.113.11', GENERATION_WINDOW, GENERATION_LIMIT, true)['limited']);
$stored = json_decode((string) file_get_contents($genLog), true);
same('expired entries pruned on write', ['203.0.113.10', '203.0.113.11'], array_keys($stored));
same('expired hit replaced by the new one', 1, count($stored['203.0.113.11']));

file_put_contents($genLog, 'not json');
same('corrupt log treated as empty', false, rateLimit($genLog, '203.0.113.12', GENERATION_WINDOW, GENERATION_LIMIT, true)['limited']);

same('rateKey IPv4', '198.51.100.7', rateKey('198.51.100.7'));
same('rateKey IPv6 groups the /64', '2001:db8:1:2::/64', rateKey('2001:db8:1:2:3:4:5:6'));
same('rateKey IPv4-mapped uses the IPv4', '198.51.100.7', rateKey('::ffff:198.51.100.7'));

if (function_exists('pcntl_fork')) {
    $race = "{$TMP}/data/race.json";
    $pids = [];
    for ($i = 0; $i < 12; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $r = rateLimit($race, '198.51.100.99', 3600, 5, true);
            exit($r !== null && !$r['limited'] ? 0 : 1);
        }
        $pids[] = $pid;
    }
    $ok = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $ok += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }
    same('flock: 12 parallel hits with limit 5, exactly 5 allowed', 5, $ok);
    same('flock: log holds exactly 5 timestamps', 5, count(json_decode((string) file_get_contents($race), true)['198.51.100.99'] ?? []));
} else {
    skip('flock race', 'pcntl not available');
}

$roLog = "{$TMP}/ro/ip_log.json";
mkdir(dirname($roLog));
chmod(dirname($roLog), 0500);
if (@fopen($roLog, 'c+') === false) {
    $previousLog = ini_set('error_log', "{$TMP}/error.log"); // the expected error_log() line stays out of the report
    same('unwritable log: fails closed (null)', null, rateLimit($roLog, '198.51.100.7', 60, 1, true));
    ini_set('error_log', (string) $previousLog);
} else {
    skip('unwritable log', 'running as a user that can write anywhere');
}
chmod(dirname($roLog), 0700);

// ─── 11. Output helpers ───────────────────────────────────────────────────────

section('11. LLMR v2.1 output and UTF-8 metadata');

same('LLMR_VERSION', '2.1', LLMR_VERSION);
same('user agent', 'LLMRBot/2.1 (+https://open-llmr.org)', LLMR_UA);

$source = (string) file_get_contents(__DIR__ . '/../config/generate.php');
check('no mb_convert_encoding(..., HTML-ENTITIES) left', !preg_match('/mb_convert_encoding\([^;]*HTML-ENTITIES/', $source));
check('no automatic redirect following left', !preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*true/', $source));
check("output version is LLMR_VERSION ('v' => LLMR_VERSION)", (bool) preg_match("/'v'\s*=>\s*LLMR_VERSION/", $source));

$utf8 = '<!doctype html><html><head><meta charset="utf-8"><title>Café déjà vu – “quotes” 日本語</title>'
      . '<meta name="description" content="Très bien, ça marche"><meta name="author" content="Raphaël Reck"></head>'
      . '<body><h2>Résumé</h2><p>Un deux trois</p></body></html>';
$m = extractMeta($utf8, 'https://site.example.com/cafe', 'text/html; charset=utf-8');
same('UTF-8 title kept exactly', 'Café déjà vu – “quotes” 日本語', $m['title']);
same('UTF-8 description kept exactly', 'Très bien, ça marche', $m['description']);
same('UTF-8 author kept exactly', 'Raphaël Reck', $m['author']);
same('UTF-8 h2 tag', ['résumé'], $m['tags']);

$m = extractMeta("<html><head><title>Caf\xE9 \x93cr\xE8me\x94</title></head><body></body></html>", 'https://site.example.com/', 'text/html; charset=windows-1252');
same('windows-1252 (from Content-Type) converted', 'Café “crème”', $m['title']);
$m = extractMeta("<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=iso-8859-1\"><title>Na\xEFve</title></head></html>", 'https://site.example.com/');
same('ISO-8859-1 (from <meta>) converted', 'Naïve', $m['title']);
$m = extractMeta("<html><head><title>bad \xC3\x28 bytes</title></head></html>", 'https://site.example.com/', 'text/html');
check('invalid UTF-8 scrubbed to valid UTF-8', mb_check_encoding($m['title'], 'UTF-8') && json_encode($m) !== false, $m['title']);
$m = extractMeta("<html><head><title>x</title></head></html>", 'https://site.example.com/', 'text/html; charset=x-unknown-zz');
same('unknown charset falls back to UTF-8', 'x', $m['title']);
$m = extractMeta('', 'https://site.example.com/', 'text/html');
same('empty body does not throw', '', $m['title']);

$page = buildPage(['title' => 'T', 'description' => '', 'date' => '', 'tags' => [], 'wordCount' => 0, 'readingTime' => 0, 'codeBlocks' => 0], 'https://site.example.com/x', str_repeat('é', 400));
same('summary capped at 300 characters (schema maxLength)', 300, mb_strlen($page['sum']));

// ─── 12. Real curl transport (local server) ───────────────────────────────────

section('12. curl transport against a local php -S (pinning, streaming cap, no auto-follow, protocols)');

$site = "{$TMP}/site";
mkdir("{$site}/config", 0700, true);
mkdir("{$site}/data", 0700, true);
copy(__DIR__ . '/../config/generate.php', "{$site}/config/generate.php");
file_put_contents("{$site}/data/.htaccess", "Order deny,allow\nDeny from all\n");
file_put_contents("{$site}/router.php", <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/config/generate.php') {
    file_put_contents(__DIR__ . '/hits.log', $path . "\n", FILE_APPEND);
}
switch ($path) {
    case '/big':
        // About 47 MB; logs how many chunks went out before the client hung up
        ignore_user_abort(true);
        header('Content-Type: text/html');
        $chunk = str_repeat('<p>0123456789abcdef</p>', 2048);
        $sent  = 0;
        for ($i = 0; $i < 1000; $i++) {
            echo $chunk;
            flush();
            if (connection_aborted()) { break; }
            $sent++;
        }
        file_put_contents(__DIR__ . '/big.log', (string) $sent);
        exit;
    case '/small':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><head><title>small</title></head><body>hello</body></html>';
        exit;
    case '/ua':
        header('Content-Type: text/plain');
        echo $_SERVER['HTTP_USER_AGENT'] ?? '';
        exit;
    case '/redirect-rel':
        header('Location: /small', true, 302);
        exit;
    case '/redirect-private':
        header('Location: http://127.0.0.1/', true, 302);
        exit;
    case '/redirect-loop':
        header('Location: /redirect-loop', true, 302);
        exit;
}
return false;
PHP);

$server = null;
$port   = 0;
for ($attempt = 0; $attempt < 3 && $server === null; $attempt++) {
    $port = random_int(20000, 45000);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $site, "{$site}/router.php"],
                      [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$TMP}/server.out", 'a'], 2 => ['file', "{$TMP}/server.out", 'a']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if ($sock) {
            fclose($sock);
            $server = $proc;
            break;
        }
        usleep(100000);
    }
    if ($server === null && is_resource($proc)) {
        proc_terminate($proc);
    }
}
if ($server !== null) {
    register_shutdown_function(function () use ($server, $MAIN_PID): void {
        if (getmypid() === $MAIN_PID) {
            proc_terminate($server);
        }
    });
}

$hits = function () use ($site): array {
    $f = "{$site}/hits.log";
    $h = is_file($f) ? array_values(array_filter(explode("\n", (string) file_get_contents($f)))) : [];
    @unlink($f);
    return $h;
};

/** A request as prepareRequest() would build it, pinned to the local server. */
$pinned = fn(string $path, int $cap = CAP_PAGE, string $scheme = 'http') => [
    'url'  => "{$scheme}://pin-test.invalid:{$port}{$path}", 'host' => 'pin-test.invalid', 'kind' => 'name',
    'port' => $port, 'ip' => '127.0.0.1', 'cap' => $cap, 'timeout' => 10, 'accept' => ACCEPT_HTML,
];

if ($server === null) {
    skip('real transport tests', 'could not start php -S');
} else {
    $hits();

    $t0 = microtime(true);
    $r  = curlExchange(['big' => $pinned('/big', 65536)])['big'];
    $dt = microtime(true) - $t0;
    same('cap: body stops at exactly 64 KiB', 65536, strlen($r['body']));
    same('cap: flagged truncated, not an error', [true, '', 200], [$r['truncated'], $r['error'], $r['status']]);
    for ($i = 0; $i < 100 && !is_file("{$site}/big.log"); $i++) {
        usleep(50000);
    }
    $sentChunks = (int) @file_get_contents("{$site}/big.log");
    check('cap: transfer aborted, server stopped early', $sentChunks < 500,
          "server sent {$sentChunks} of 1000 chunks in " . sprintf('%.2fs', $dt));

    $r = curlExchange(['small' => $pinned('/small')])['small'];
    same('pin: unresolvable host reached through the pinned address', [200, '', false], [$r['status'], $r['error'], $r['truncated']]);
    check('pin: body intact', str_contains($r['body'], 'hello'));

    $r = curlExchange(['ua' => $pinned('/ua')])['ua'];
    same('User-Agent sent', LLMR_UA, $r['body']);

    $hits();
    $r = curlExchange(['rd' => $pinned('/redirect-rel')])['rd'];
    same('no automatic redirect: 302 returned with its Location', [302, '/small'], [$r['status'], $r['location']]);
    check('no automatic redirect: /small never fetched', !in_array('/small', $hits(), true));

    foreach (['gopher', 'ftp', 'dict', 'file'] as $proto) {
        $r = curlExchange(['p' => $pinned('/small', 1024, $proto)])['p'];
        same("CURLOPT_PROTOCOLS refuses {$proto}://", ['transport', ''], [$r['error'], $r['body']]);
    }
    same('CURLOPT_PROTOCOLS: nothing reached the server', [], $hits());

    $multi = curlExchange(['a' => $pinned('/small'), 'b' => $pinned('/big', 4096), 'c' => $pinned('/missing')]);
    same('curl_multi: per-request results', [200, 200, 404], [$multi['a']['status'], $multi['b']['status'], $multi['c']['status']]);
    same('curl_multi: cap applied per handle', [false, true], [$multi['a']['truncated'], $multi['b']['truncated']]);
    $hits();

    // Full path: stub DNS says site.example.com is public, the wrapper points the pinned
    // connection at the local server, and the real curl transport does the HTTP.
    useStubDns();
    $GLOBALS['httpLog'] = [];
    Net::$transport = function (array $requests) use ($port): array {
        foreach ($requests as $key => $req) {
            $GLOBALS['httpLog'][] = $req;
            $path = substr($req['url'], strlen($req['origin']));
            $requests[$key] = ['url' => "http://{$req['host']}:{$port}{$path}", 'port' => $port, 'ip' => '127.0.0.1'] + $req;
        }
        return curlExchange($requests);
    };
    $r = guardedFetch('https://site.example.com/redirect-rel', CAP_PAGE, 10, $scope, ACCEPT_HTML);
    same('end to end: relative redirect followed', [true, 'https://site.example.com/small'], [$r['ok'], $r['url']]);
    same('end to end: server saw both hops', ['/redirect-rel', '/small'], $hits());

    $r = guardedFetch('https://site.example.com/redirect-private', CAP_PAGE, 10, null, ACCEPT_HTML);
    same('end to end: real 302 to http://127.0.0.1/ refused', 'private_address', $r['error']);
    same('end to end: only the first hop was sent', ['/redirect-private'], $hits());

    $r = guardedFetch('https://site.example.com/redirect-loop', CAP_PAGE, 10, $scope, ACCEPT_HTML);
    same('end to end: redirect loop stops after ' . MAX_REDIRECTS . ' redirects', 'too_many_redirects', $r['error']);
    same('end to end: 1 + ' . MAX_REDIRECTS . ' requests', 1 + MAX_REDIRECTS, count($hits()));
    Net::$transport = null;
}

// ─── 13. Endpoint: refusals and rate-limit order ──────────────────────────────

section('13. Endpoint (copy in a temp docroot): bad input is refused, Mode B records only valid input');

$call = function (array|null $json, string $method = 'POST') use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}/config/generate.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, json_decode($body, true) ?? $body];
};

if ($server === null) {
    skip('endpoint tests', 'could not start php -S');
} else {
    $ipLog   = "{$site}/data/ip_log.json";
    $discLog = "{$site}/data/discovery_log.json";

    same('GET is 405', [405, ['error' => 'method_not_allowed']], $call(null, 'GET'));
    same('malformed url', [400, ['error' => 'invalid_url']], $call(['url' => 'not a url']));
    same('url as an array', [400, ['error' => 'invalid_url']], $call(['url' => ['http://x.com']]));

    $modeA = [
        ['http://127.0.0.1/', 'private_address'],
        ['http://169.254.169.254/latest/meta-data/', 'private_address'],
        ['http://[::ffff:10.0.0.1]/', 'private_address'],
        ['http://2130706433/', 'bad_host'],
        ['http://localhost/', 'bad_host'],
        ['https://93.184.216.34:8443/', 'bad_port'],
        ['ftp://93.184.216.34/', 'bad_scheme'],
    ];
    foreach ($modeA as [$url, $reason]) {
        [$status, $data] = $call(['url' => $url]);
        $got = $data['error'] === 'refused_url' ? $data['reason'] : $data['error'];
        same("Mode A refuses {$url}", [400, $reason], [$status, $got]);
    }
    $disc = json_decode((string) @file_get_contents($discLog), true) ?: [];
    same('Mode A: every lookup counted in data/discovery_log.json', count($modeA), count($disc['127.0.0.1'] ?? []));
    same('Mode A: nothing recorded in the generation log', false, is_file($ipLog) && isset((json_decode((string) file_get_contents($ipLog), true) ?: [])['127.0.0.1']));

    $modeB = [
        [['url' => 'http://93.184.216.34/', 'sitemap_url' => 'http://93.184.216.35/sitemap.xml'], 400, 'refused_url', 'sitemap_url', 'off_origin'],
        [['url' => 'http://93.184.216.34/', 'sitemap_url' => 'http://93.184.216.34:8080/sitemap.xml'], 400, 'refused_url', 'sitemap_url', 'bad_port'],
        [['url' => 'http://93.184.216.34/', 'sitemap_url' => 'not a url'], 400, 'invalid_sitemap_url', null, null],
        [['url' => 'http://10.1.1.1/', 'sitemap_url' => 'http://10.1.1.1/sitemap.xml'], 400, 'refused_url', 'url', 'private_address'],
        [['url' => 'http://[::1]/', 'sitemap_url' => 'http://[::1]/sitemap.xml'], 400, 'refused_url', 'url', 'private_address'],
    ];
    foreach ($modeB as [$payload, $status, $error, $field, $reason]) {
        [$gotStatus, $data] = $call($payload);
        same("Mode B refuses {$payload['url']} + {$payload['sitemap_url']}",
             [$status, $error, $field, $reason],
             [$gotStatus, $data['error'] ?? null, $data['field'] ?? null, $data['reason'] ?? null]);
    }
    $gen = is_file($ipLog) ? (json_decode((string) file_get_contents($ipLog), true) ?: []) : [];
    same('Mode B: refused input never recorded (daily slot kept)', false, isset($gen['127.0.0.1']));

    // Discovery limit reached: Mode A answers 429 before looking at the URL
    file_put_contents($discLog, json_encode(['127.0.0.1' => array_fill(0, DISCOVERY_LIMIT, time())]));
    [$status, $data] = $call(['url' => 'http://127.0.0.1/']);
    same('Mode A over its limit: 429 discovery_rate_limited', [429, 'discovery_rate_limited'], [$status, $data['error'] ?? null]);
    check('Mode A over its limit: next_available given', isset($data['next_available']) && strtotime($data['next_available']) > time());
    [$status, $data] = $call(['url' => 'http://10.1.1.1/', 'sitemap_url' => 'http://10.1.1.1/sitemap.xml']);
    same('Mode B has its own limit (discovery limit does not apply)', [400, 'refused_url'], [$status, $data['error'] ?? null]);

    // Generation already used today: Mode B answers 429 before any validation or DNS
    file_put_contents($ipLog, json_encode(['127.0.0.1' => [time() - 60]]));
    [$status, $data] = $call(['url' => 'https://unresolvable.invalid/', 'sitemap_url' => 'https://unresolvable.invalid/sitemap.xml']);
    same('Mode B over its limit: 429 rate_limited first', [429, 'rate_limited'], [$status, $data['error'] ?? null]);
    check('Mode B over its limit: next_available about 24 h after the last run',
          isset($data['next_available']) && abs(strtotime($data['next_available']) - (time() - 60 + GENERATION_WINDOW)) <= 5);
}

// ─── Summary ──────────────────────────────────────────────────────────────────

echo "\n{$passed} passed, {$failed} failed, {$skipped} skipped\n";
exit($failed === 0 ? 0 : 1);
