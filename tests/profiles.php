<?php

declare(strict_types=1);

require __DIR__ . '/../src/Profiles.php';

use function TotalStaticFileCache\cacheFile;
use function TotalStaticFileCache\profiles;

$checks = 0;
function same(mixed $expected, mixed $actual): void
{
    global $checks;
    ++$checks;
    if ($expected !== $actual) {
        throw new RuntimeException(var_export([$expected, $actual], true));
    }
}
foreach (profiles() as $p) {
    $major = $p['major'];
    $prefix = match (true) {
        $major === 2 => 'example.org',
        $major <= 7 => 'https/example.org',
        $major <= 9 => 'https/example.org/443',
        default => 'https_example.org_443',
    };
    $index = $major >= 10 ? 'index' : 'index.html';
    same($prefix . '/' . $index, cacheFile($p, 'https://example.org/'));
    same($prefix . '/about/' . $index, cacheFile($p, 'https://example.org/about/'));
    same($prefix . '/about.html/' . $index, cacheFile($p, 'https://example.org/about.html'));
    same($prefix . '/subdir/news/' . $index, cacheFile($p, 'https://example.org/subdir/news/'));
    same($prefix . '/caf%C3%A9/' . $index, cacheFile($p, 'https://example.org/caf%C3%A9/'));
    same($prefix . '/feed.xml' . ($major >= 10 ? '/index' : ''), cacheFile($p, 'https://example.org/feed.xml'));
    $portPrefix = match (true) {
        $major === 2 => 'example.org:8443',
        $major <= 7 => 'https/example.org',
        $major <= 9 => 'https/example.org/8443',
        default => 'https_example.org_8443',
    };
    same($portPrefix . '/' . $index, cacheFile($p, 'https://example.org:8443/'));
    same($major === 2 ? 'typo3temp/tx_nc_staticfilecache' : ($major >= 13
        ? 'typo3temp/assets/tx_staticfilecache' : 'typo3temp/tx_staticfilecache'), $p['cacheDirectory']);
    foreach (['/../secret', '/%2e%2e/secret', '/%252e/secret', '/%00/', '/%2fsecret/', '/.env', '/a//b', '/a\\b', '/foo?x=1', '/foo#bar'] as $path) {
        try {
            cacheFile($p, 'https://example.org' . $path);
        } catch (InvalidArgumentException) {
            ++$checks;
            continue;
        }
        throw new RuntimeException('Unsafe URL accepted: ' . $path);
    }
}
echo "OK: $checks source-derived profile/path checks\n";
