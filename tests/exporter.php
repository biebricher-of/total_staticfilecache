<?php

declare(strict_types=1);

// Exercise the core with controllable cache mappings and the real Apache rules.
namespace TotalStaticFileCache {
    function cacheFile(array $profile, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (isset($profile['pages']) && in_array($profile['testMapping'], ['rules-file', 'rules-symlink'], true)) {
            $destination = $profile['testOutput'] . '/full-static.htaccess';
            if ($profile['testMapping'] === 'rules-file') {
                file_put_contents($destination, 'existing rules');
            } else {
                symlink($profile['testOutput'] . '-sentinel', $destination);
            }
        }
        if ($profile['testMapping'] === 'unsafe') {
            return '../escaped';
        }
        if ($profile['testMapping'] === 'reject') {
            throw new \InvalidArgumentException('Fixture callback rejected URL');
        }
        if ($profile['testMapping'] === 'collision' && str_contains($path, 'collision-')) {
            return 'same/index';
        }
        if ($profile['testMapping'] === 'prefix' && str_contains($path, 'prefix-')) {
            return str_ends_with($path, 'prefix-a') ? 'node' : 'node/child';
        }
        if ($profile['testMapping'] === 'alias-prefix') {
            return str_ends_with($path, '/parent-alias') ? 'node' : 'node/child';
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '_' . $host . '_' . $port . '/' . ltrim(rtrim(rawurldecode($path), '/') . '/index', '/');
    }
}

namespace {
    require __DIR__ . '/../src/Exporter.php';
    require __DIR__ . '/../src/Apache.php';

    if (($argv[1] ?? '') === '--export-child') {
        array_splice($argv, 1, 1);
        exit(\TotalStaticFileCache\run([
            'id' => 'fixture', 'label' => 'Fixture profile',
            'cacheDirectory' => 'typo3temp/assets/tx_fixture', 'layout' => 'fixture',
            'typo3' => ['major' => 13], 'testMapping' => getenv('EXPORTER_TEST_MAPPING') ?: '',
            'testOutput' => in_array(getenv('EXPORTER_TEST_MAPPING'), ['rules-file', 'rules-symlink'], true)
                ? \TotalStaticFileCache\Exporter::options($argv)['output'] : '',
        ], $argv));
    }

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        ++$checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function command(array $arguments, array $phpFlags = [], string $mapping = '', bool $limited = false): array
    {
        $cli = $limited
            ? ['/bin/sh', '-c', 'trap "" XFSZ; ulimit -f 4; exec "$@"', 'sh', PHP_BINARY, __DIR__ . '/../staticfilecache-v13.php', ...$arguments]
            : [PHP_BINARY, ...$phpFlags, __FILE__, '--export-child', ...$arguments];
        $process = proc_open($cli,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_merge(getenv(), ['EXPORTER_TEST_MAPPING' => $mapping]));
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start exporter subprocess.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                removeTree($path . '/' . $entry);
            }
            rmdir($path);
        }
    }

    $temp = sys_get_temp_dir() . '/fullstatic-exporter-' . bin2hex(random_bytes(6));
    mkdir($temp, 0700);
    $server = null;
    try {
        [$status, $stdout, $stderr] = command(['--help'], ['-n', '-d', 'disable_functions=curl_init,ctype_digit']);
        check($status === 0 && str_contains($stdout, 'Usage:') && $stderr === '', 'Help must work without a callable cURL runtime.');
        [$status, , $stderr] = command(['--help', '--typo=1']);
        check($status !== 0 && str_contains($stderr, 'Unknown option'), 'Unknown CLI options must fail.');
        [$status, , $stderr] = command(['--timeout=0']);
        check($status !== 0 && str_contains($stderr, '--timeout'), 'Timeout bounds must be checked.');
        [$status, , $stderr] = command(['--base-url=http://fixture.test', '--base-url=http://fixture.test']);
        check($status !== 0 && str_contains($stderr, 'only be supplied once'), 'Duplicate singleton options must fail.');
        [$status, , $stderr] = command(['--output']);
        check($status !== 0 && str_contains($stderr, 'Missing value'), 'Missing CLI values must fail.');
        [$status, , $stderr] = command(['--base-url=http://fixture.test', '--output=' . $temp . '/no-extensions', '--urls=' . $temp . '/list'], ['-n', '-d', 'disable_functions=curl_init']);
        check($status !== 0 && str_contains($stderr, 'extensions curl and dom'), 'Missing runtime extensions must give an actionable error.');
        [$status, , $stderr] = command(['--base-url=http://fixture.test', '--output=' . $temp . '/no-zlib', '--urls=' . $temp . '/list'], ['-d', 'disable_functions=gzdecode']);
        check($status !== 0 && str_contains($stderr, 'zlib'), 'Missing zlib must fail early with an actionable error.');

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new RuntimeException("Cannot reserve fixture port: $error");
        }
        $address = stream_socket_get_name($socket, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);
        $origin = 'http://fixture.test:' . $port;
        $log = $temp . '/requests.jsonl';
        $server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/fixtures/exporter-server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']],
            $pipes, null, array_merge(getenv(), ['EXPORTER_REQUEST_LOG' => $log]));
        if (!is_resource($server)) {
            throw new RuntimeException('Cannot start HTTP fixture.');
        }
        $ready = false;
        for ($i = 0; $i < 100; ++$i) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);
                $ready = true;
                break;
            }
            usleep(20000);
        }
        check($ready, 'Fixture server must start.');
        $common = ['--base-url=' . $origin . '/site', '--resolve=fixture.test:' . $port . ':127.0.0.1', '--timeout=5'];
        $list = $temp . '/urls.txt';
        $secondList = $temp . '/more.txt';
        file_put_contents($list, "# Anonymous rendered pages\n\n/site/list/\n");
        file_put_contents($secondList, $origin . "/site/final/\n");
        $success = $temp . '/success';
        [$status, $stdout, $stderr] = command([...$common, '--urls', $list, '--urls=' . $secondList,
            '--sitemap=' . $origin . '/site/index.xml', '--sitemap=' . $origin . '/site/index.xml',
            '--cache-dir=custom/cache', '--output=' . $success]);
        check($status === 0, "Export should succeed: $stdout $stderr");
        check(file_get_contents($success . '/custom/cache/.htaccess') === "ForceType text/html\nAddDefaultCharset Off\nOptions -Indexes -MultiViews -ExecCGI\n", 'Every captured HTML response must keep an HTML MIME type, including legacy extension-preserving layouts.');
        check($stderr === '', 'Successful export must have no diagnostics.');
        check(str_contains($stdout, 'Exported 6 HTML page(s)'), 'Six unique terminal pages must be reported.');
        $body = '<!doctype html><html lang="de"><head><title>Snapshot</title></head><body>Grüße 🌍<!-- full document --><section id="plugin">rendered anonymously: 7</section><script>window.plugin={"html":"<b>unmodified</b>"};</script></body></html>';
        $cacheRoot = $success . '/custom/cache/http_fixture.test_' . $port;
        foreach (['site/list/index', 'site/final/index', 'site/index', 'site/über/index', 'site/gzip/index', 'site/transport/index'] as $path) {
            check(file_get_contents($cacheRoot . '/' . $path) === $body, "Full UTF-8/plugin body must be preserved: $path");
        }
        check(!file_exists($cacheRoot . '/site/old/index'), 'Redirect alias/body must not be exported.');
        $rules = file_get_contents($success . '/full-static.htaccess');
        check(str_contains($rules, '/site/custom/cache/http_fixture.test_') && !str_contains($rules, '/site/old'), 'Rules must map only completed terminal URLs using the supplied mount and cache override.');
        $manifest = json_decode(file_get_contents($success . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        check($manifest['complete'] === true && count($manifest['pages']) === 6 && $manifest['failures'] === [], 'Manifest must describe completed terminal pages.');
        check($manifest['pages'][0]['bytes'] === strlen($body) && $manifest['pages'][0]['sha256'] === hash('sha256', $body), 'Manifest must reflect actual body bytes/hash.');
        check(!file_exists($success . '/custom/cache/manifest.json'), 'Manifest must stay outside the deployable cache.');
        $requests = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES));
        $uris = array_column($requests, 'uri');
        check(count(array_filter($uris, static fn ($uri) => $uri === '/site/index.xml')) === 1, 'Recursive/duplicate sitemap cycle must be fetched once.');
        check(in_array('/site/pages.xml?chunk=1&source=test', $uris, true), 'Sitemap query strings must survive XML entity decoding.');
        foreach ($requests as $request) {
            check($request['cookie'] === null && $request['authorization'] === null, 'HTTP requests must carry no credentials or cookies.');
            check($request['cache_control'] === 'no-cache' && $request['pragma'] === 'no-cache'
                && $request['crawler'] === '1', 'Requests must signal source-cache bypass without introducing session cookies.');
        }

        file_put_contents($list, "/site/space%20here/\n/site/über-two/\n");
        [$status, , $stderr] = command([...$common, '--urls=' . $list, '--output=' . $temp . '/encoded-pages']);
        check($status === 0 && $stderr === '', 'Percent-space and Unicode page URLs must export: ' . $stderr);
        $encodedManifest = json_decode(file_get_contents($temp . '/encoded-pages/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        check(array_column($encodedManifest['pages'], 'url') === [$origin . '/site/space%20here/', $origin . '/site/%C3%BCber-two/'],
            'Manifest URLs must use browser-requestable percent-encoded paths.');
        file_put_contents($list, "/site/cookie\n");
        [$status, , $stderr] = command([...$common, '--urls=' . $list, '--output=' . $temp . '/cookie-opt-in', '--allow-set-cookie']);
        check($status === 0 && $stderr === '', 'Explicit opt-in must permit anonymous cookie-setting HTML: ' . $stderr);
        $cookieManifest = json_decode(file_get_contents($temp . '/cookie-opt-in/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        check($cookieManifest['allowSetCookieResponses'] === true && $cookieManifest['complete'] === true,
            'Manifest must record the relaxed response-cookie policy.');
        check(file_get_contents($temp . '/cookie-opt-in/typo3temp/assets/tx_fixture/http_fixture.test_' . $port . '/site/cookie/index') === $body,
            'Cookie opt-in must preserve full HTML without replaying cookie headers.');
        file_put_contents($list, "/site/alias-one\n/site/alias-two\n");
        [$status, , $stderr] = command([...$common, '--urls=' . $list, '--output=' . $temp . '/slash-aliases']);
        check($status === 0 && $stderr === '', 'Converging trailing-slash redirects must succeed: ' . $stderr);
        $aliasManifest = json_decode(file_get_contents($temp . '/slash-aliases/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        check(array_column($aliasManifest['pages'], 'url') === [$origin . '/site/slash/'], 'Only the terminal trailing-slash URL may be exported.');
        check(file_get_contents($temp . '/slash-aliases/typo3temp/assets/tx_fixture/http_fixture.test_' . $port . '/site/slash/index') === $body, 'Trailing-slash terminal HTML must be preserved.');
        $aliasRequests = array_map(static fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['uri'], file($log, FILE_IGNORE_NEW_LINES));
        check(count(array_filter($aliasRequests, static fn ($uri) => $uri === '/site/slash')) === 1, 'Known redirect aliases must not be fetched again.');
        check(count(array_filter($aliasRequests, static fn ($uri) => $uri === '/site/slash/')) === 1, 'Converging terminal pages must be fetched only once.');
        file_put_contents($list, "/site/parent-alias\n");
        [$status, , $stderr] = command([...$common, '--urls=' . $list, '--output=' . $temp . '/prefix-alias'], [], 'alias-prefix');
        check($status === 0 && $stderr === '', 'A redirect alias must not retain file/directory reservations: ' . $stderr);
        check(file_get_contents($temp . '/prefix-alias/typo3temp/assets/tx_fixture/node/child') === $body, 'Terminal may safely occupy a former alias directory.');

        $serial = 0;
        $failure = static function (array $arguments, string $expected, string $mapping = '', ?string $out = null) use (&$serial, $common, $temp): array {
            $out ??= $temp . '/failure-' . ++$serial;
            [$status, $stdout, $stderr] = command([...$common, ...$arguments, '--output=' . $out], [], $mapping);
            check($status !== 0, 'Expected export failure: ' . implode(' ', $arguments));
            check(str_contains($stderr, $expected), "Expected diagnostic '$expected', got: $stderr");
            check(!file_exists($out . '/full-static.htaccess') && !str_contains($stdout, 'Ready for review:'), 'A failed export must never generate/announce ready rules.');
            return [$out, $stderr];
        };
        $requestCount = static fn (): int => count(file($log, FILE_IGNORE_NEW_LINES));
        foreach (['/site/a?personal=1', '/site/a#fragment', '/site/a?', '/site/a#', '/outside/', '/site-other/a',
            'http://other.invalid/site/a', 'ftp://fixture.test/site/a', 'http://user:secret@fixture.test:' . $port . '/site/a',
            '/site/%2e%2e/outside', '/site/%252e%252e/outside', '/site/a%2fb', '/site/a%5cb', '/site/../outside',
            '/site/./a', '/site//a', '/site/%00a', '/site/%zz', '/site/a\\b'] as $unsafe) {
            file_put_contents($list, $unsafe . "\n");
            $before = $requestCount();
            $failure(['--urls=' . $list], 'Export failed:');
            check($requestCount() === $before, 'Unsafe page URLs must fail before any HTTP request: ' . $unsafe);
        }
        foreach (['collision' => ["/site/collision-a\n/site/collision-b\n", 'Cache path collision'],
            'prefix' => ["/site/prefix-a\n/site/prefix-b\n", 'Cache file/directory collision'],
            'unsafe' => ["/site/safe\n", 'Unsafe relative cache path'],
            'reject' => ["/site/safe\n", 'Fixture callback rejected URL']] as $mapping => [$contents, $expected]) {
            file_put_contents($list, $contents);
            $before = $requestCount();
            $failure(['--urls=' . $list], $expected, $mapping);
            check($requestCount() === $before, 'All callback paths/collisions must be validated before page fetch.');
        }
        file_put_contents($list, "/site/prefix-b\n/site/prefix-a\n");
        $failure(['--urls=' . $list], 'Cache file/directory collision', 'prefix');

        foreach (['cross-origin' => 'origin/site mount', 'cross-port' => 'origin/site mount', 'cross-scheme' => 'origin/site mount',
            'outside' => 'origin/site mount', 'redirect-query' => 'query/fragment', 'redirect-traversal' => 'Dot traversal',
            'login-redirect' => 'Authentication path', 'cookie' => 'Set-Cookie', 'cookie-redirect' => 'Set-Cookie',
            'unauthorized' => 'received 401', 'error' => 'received 500', 'not-found' => 'received 404', 'plain' => 'Expected text/html',
            'loop' => 'Redirect cycle', 'many-redirects/0' => 'Too many redirects', 'large' => 'exceeds 16 MiB'] as $route => $expected) {
            file_put_contents($list, '/site/' . $route . "\n");
            [$out] = $failure(['--urls=' . $list], $expected);
            $manifest = json_decode(file_get_contents($out . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            check($manifest['complete'] === false && $manifest['pages'] === [] && count($manifest['failures']) === 1, 'Failed page must not enter the cache manifest.');
        }
        foreach (['external.xml' => 'origin/site mount', 'outside.xml' => 'origin/site mount',
            'query-page.xml' => 'query/fragment', 'xxe.xml' => 'DTDs and external entities',
            'local-xxe.xml' => 'DTDs and external entities', 'malformed.xml' => 'Invalid sitemap XML',
            'duplicate-loc.xml' => 'exactly one', 'empty.xml' => 'No page URLs',
            'sitemap-escape' => 'origin/site mount', 'sitemap-cookie' => 'Set-Cookie'] as $route => $expected) {
            $failure(['--sitemap=' . $origin . '/site/' . $route], $expected);
        }
        check(!str_contains(file_get_contents($log), 'xxe-sentinel'), 'XML parser must not fetch external entities.');
        check(!str_contains(file_get_contents($log), '"uri":"\\/site\\/login\\/"'), 'Login redirect target must not be requested.');
        check(!str_contains(file_get_contents($log), '"uri":"\\/outside'), 'Outside-mount redirect target must not be requested.');

        // Mixed results still record failures and withhold the rules snippet.
        file_put_contents($list, "/site/final/\n/site/error\n/site/list/\n");
        [$out] = $failure(['--urls=' . $list], '1 page(s) failed');
        $manifest = json_decode(file_get_contents($out . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        check(count($manifest['pages']) === 2 && count($manifest['failures']) === 1, 'Exporter must report failures while allowing partial staging.');

        file_put_contents($list, "/site/final/\n");
        $before = $requestCount();
        [$status, $stdout, $stderr] = command([...$common, '--urls=' . $list, '--output=' . $success]);
        check($status !== 0 && str_contains($stderr, 'absent or empty') && !str_contains($stdout, 'Ready for review:'), 'Non-empty output must fail without announcing readiness.');
        check(file_get_contents($cacheRoot . '/site/final/index') === $body && $requestCount() === $before, 'Existing live output must remain untouched.');
        foreach (['../escape', '/absolute', 'foo//bar', 'foo/./bar', 'full-static.htaccess/cache'] as $directory) {
            $failure(['--urls=' . $list, '--cache-dir=' . $directory], $directory === 'full-static.htaccess/cache' ? 'Cache directory' : 'Unsafe relative cache path');
        }
        foreach (['a.b', 'my cache'] as $directory) {
            $before = $requestCount();
            $failure(['--urls=' . $list, '--cache-dir=' . $directory], 'Cache directory');
            check($requestCount() === $before, 'Apache-incompatible cache directory must fail before any HTTP request.');
        }
        mkdir($temp . '/target');
        symlink($temp . '/target', $temp . '/linked');
        $failure(['--urls=' . $list], 'Symlink component', '', $temp . '/linked/out');
        symlink($temp . '/missing', $temp . '/dangling');
        $failure(['--urls=' . $list], 'Symlink component', '', $temp . '/dangling');
        check(scandir($temp . '/target') === ['.', '..'], 'Symlink target must not be written.');

        // A root site and a redirecting sitemap also work, using default cache-directory settings.
        [$status, $stdout, $stderr] = command(['--base-url=' . $origin, '--urls=' . $list,
            '--resolve=fixture.test:' . $port . ':127.0.0.1', '--output=' . $temp . '/root']);
        check($status === 0 && $stderr === '', 'Root mount export must succeed: ' . $stderr);
        check(str_contains(file_get_contents($temp . '/root/full-static.htaccess'), '/typo3temp/assets/tx_fixture/http_fixture.test_'), 'Root mount must use the profile cache default.');
        [$status, , $stderr] = command([...$common, '--sitemap=' . $origin . '/site/sitemap-redirect', '--output=' . $temp . '/sitemap-redirect']);
        check($status === 0 && $stderr === '', 'Same-scope sitemap redirects must work: ' . $stderr);

        // Real wrapper: the page and manifest fit, but the deployment rules exceed RLIMIT_FSIZE.
        file_put_contents($list, "/\n");
        $limitedOutput = $temp . '/limited';
        [$status, $stdout, $stderr] = command(['--base-url=' . $origin, '--urls=' . $list,
            '--resolve=fixture.test:' . $port . ':127.0.0.1', '--output=' . $limitedOutput], [], '', true);
        check($status === 1 && str_contains($stderr, 'Cannot write file: ' . $limitedOutput . '/full-static.htaccess'), 'A limited rules write must fail normally, not terminate on SIGXFSZ: ' . $stderr);
        check(!file_exists($limitedOutput . '/full-static.htaccess') && !str_contains($stdout, 'Ready for review:'), 'A short rules write must never expose or announce the final deployment filename.');
        check(array_diff(scandir($limitedOutput), ['.', '..', 'manifest.json', 'typo3temp']) === [], 'Failed publication must not leave temporary sibling files.');
        $limitedManifest = is_file($limitedOutput . '/manifest.json') ? json_decode(file_get_contents($limitedOutput . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR) : null;
        check(($limitedManifest['complete'] ?? false) === false, 'Failed rules publication must not leave a complete manifest.');

        // Simulate a destination appearing after the initial empty-directory check.
        file_put_contents($list, "/site/final/\n");
        foreach (['rules-file', 'rules-symlink'] as $mapping) {
            $out = $temp . '/' . $mapping;
            file_put_contents($out . '-sentinel', 'existing rules');
            [$status, $stdout, $stderr] = command([...$common, '--urls=' . $list, '--output=' . $out], [], $mapping);
            check($status === 1 && !str_contains($stdout, 'Ready for review:'), 'Existing deployment rules must prevent publication.');
            check(str_contains($stderr, $mapping === 'rules-file' ? 'Cannot exclusively publish file:' : 'Symlink file refused:'), 'Existing destinations must fail with an exclusive-publication diagnostic: ' . $stderr);
            check(file_get_contents($out . '/full-static.htaccess') === 'existing rules' && file_get_contents($out . '-sentinel') === 'existing rules', 'Existing files and symlink targets must never be overwritten.');
            check(is_link($out . '/full-static.htaccess') === ($mapping === 'rules-symlink'), 'Publication must not replace an existing symlink.');
            check(!file_exists($out . '/manifest.json') && array_diff(scandir($out), ['.', '..', 'full-static.htaccess', 'typo3temp']) === [], 'Failed publication must remove its complete manifest and temporary sibling.');
        }

        // The static rules and exporter must agree, including after redirects and below a mount.
        foreach (['', '/site'] as $mount) {
            $scoped = ['--base-url=' . $origin . $mount, '--resolve=fixture.test:' . $port . ':127.0.0.1'];
            foreach (['vendor', 'typo3_src', 'config', 'var', 'src', 'tests', 'node_modules'] as $name) {
                file_put_contents($list, $mount . '/news/' . $name . "/\n");
                [$status, , $stderr] = command([...$scoped, '--urls=' . $list, '--output=' . $temp . '/nested-' . ++$serial]);
                check($status === 0 && $stderr === '', 'Nested private-name page must remain exportable: ' . $stderr);
            }
            foreach (['/vendor/', '/typo3_src/', '/config/', '/var/', '/src/', '/tests/', '/node_modules/',
                '/.hidden/', '/news/.hidden/', '/news/shell.php/', '/index.php', '/news/template.twig',
                '/typo3temp/assets/tx_fixture/page/', '/denied-redirect'] as $path) {
                file_put_contents($list, $mount . $path . "\n");
                $out = $temp . '/denied-' . ++$serial;
                [$status, $stdout, $stderr] = command([...$scoped, '--urls=' . $list, '--output=' . $out]);
                check($status === 1 && str_contains($stderr, 'Static delivery denies'), 'Undeliverable page must fail export: ' . $path . ' ' . $stderr);
                check(!file_exists($out . '/full-static.htaccess') && !str_contains($stdout, 'Ready for review:'), 'Denied pages must never produce ready rules.');
            }
        }

        file_put_contents($list, "/site/slow\n");
        [$status, $stdout, $stderr] = command(['--base-url=' . $origin . '/site', '--urls=' . $list,
            '--resolve=fixture.test:' . $port . ':127.0.0.1', '--timeout=1', '--output=' . $temp . '/timeout']);
        check($status !== 0 && str_contains($stderr, 'HTTP transport error') && !file_exists($temp . '/timeout/full-static.htaccess'), 'Request timeouts must fail without ready rules.');
        fwrite(STDOUT, "OK: $checks exporter checks (real CLI subprocesses and HTTP fixture).\n");
    } finally {
        if (is_resource($server)) {
            proc_terminate($server);
            proc_close($server);
        }
        removeTree($temp);
    }
}
