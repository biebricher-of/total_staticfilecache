<?php

declare(strict_types=1);

namespace TotalStaticFileCache;

/** Defaults verified against the representative upstream releases in COMPATIBILITY.md. */
function profiles(): array
{
    $versions = [
        2 => ['2.5.2', '4.5.0–6.2.99'],
        3 => ['3.6.0', '6.2.0–7.99.99'],
        4 => ['4.0.0', '7.6.0–8.6.99'],
        5 => ['5.0.0', '8.7.0–8.7.99'],
        6 => ['6.0.0', '8.7.0–9.3.99'],
        7 => ['7.0.0', '8.7.0–9.5.99'],
        8 => ['8.0.0', '8.7.0–9.5.99'],
        9 => ['9.0.0', '8.7.0–10.0.99'],
        10 => ['10.0.0', '9.5.0–10.9.99'],
        11 => ['11.0.0', '9.5.0–10.4.99'],
        12 => ['12.4.0', '10.4.6–11.5.99'],
        13 => ['13.0.0', '11.5.0–12.4.99'],
        14 => ['14.0.0', '12.4.0–13.4.99'],
        15 => ['15.0.0', '12.4.0–13.4.99'],
        16 => ['16.2.0', '14.0.0–14.4.99'],
    ];
    $profiles = [];
    foreach ($versions as $major => [$release, $typo3]) {
        $id = ($major === 2 ? 'nc_staticfilecache' : 'staticfilecache') . '-v' . $major;
        $profiles[$id] = [
            'id' => $id,
            'major' => $major,
            'label' => ($major === 2 ? 'nc_staticfilecache' : 'StaticFileCache') . ' ' . $major . '.x',
            'referenceRelease' => $release,
            'typo3' => ['referenceReleaseRange' => $typo3],
            'cacheDirectory' => $major === 2 ? 'typo3temp/tx_nc_staticfilecache'
                : ($major >= 13 ? 'typo3temp/assets/tx_staticfilecache' : 'typo3temp/tx_staticfilecache'),
            'layout' => $major === 2 ? 'host' : ($major <= 7 ? 'scheme/host' : ($major <= 9 ? 'scheme/host/port' : 'scheme_host_port')),
            'fileTypes' => $major === 9 ? ['sfc', 'xml', 'rss'] : ['xml', 'rss'],
            'decodePath' => false,
        ];
    }
    return $profiles;
}

function profile(string $id): array
{
    return profiles()[$id] ?? throw new \InvalidArgumentException('Unknown cache profile: ' . $id);
}

/** Return an HTML filename relative to the configured cache directory; never include query data. */
function cacheFile(array $profile, string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new \InvalidArgumentException('Cache URLs must be absolute HTTP(S) URLs without credentials, query or fragment: ' . $url);
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    if (!preg_match('~\A(?:[a-z0-9][a-z0-9.-]*|\[[0-9a-f:]+\])\z~D', $host)) {
        throw new \InvalidArgumentException('Use a DNS, IPv4 or bracketed IPv6 hostname (IDNs must be punycode).');
    }
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = $parts['path'] ?? '/';
    validateCachePath($path);
    if ($profile['decodePath'] ?? false) {
        $path = rawurldecode($path);
        validateCachePath($path);
    }
    $path = trim($path, '/');
    $prefix = match ($profile['layout']) {
        // The old extension used HTTP_HOST, including an explicit non-default port.
        'host' => $host . (isset($parts['port']) ? ':' . $parts['port'] : ''),
        'scheme/host' => $scheme . '/' . $host,
        'scheme/host/port' => $scheme . '/' . $host . '/' . $port,
        'scheme_host_port' => $scheme . '_' . $host . '_' . $port,
        default => throw new \InvalidArgumentException('Unknown filesystem layout.'),
    };
    $relative = $prefix . ($path === '' ? '' : '/' . $path);
    if ($profile['major'] >= 10) {
        return $relative . '/index';
    }
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    return $extension !== '' && in_array($extension, $profile['fileTypes'], true)
        ? $relative : $relative . '/index.html';
}

/** Reject ambiguous or dangerous paths before any filesystem or Apache interpolation. */
function validateCachePath(string $path): void
{
    if (!str_starts_with($path, '/') || str_contains($path, '//')
        || preg_match('~[\x00-\x20\x7f\\\\"<>#?]~', $path)
        || preg_match('~%(?![a-fA-F0-9]{2})~', $path)
        || preg_match('~%(?:00|0[0-9a-f]|1[0-9a-f]|7f|2f|5c|25|3f|23)~i', $path)) {
        throw new \InvalidArgumentException('Unsafe or ambiguous URL path: ' . $path);
    }
    foreach (explode('/', rawurldecode($path)) as $segment) {
        if ($segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
            throw new \InvalidArgumentException('Hidden and traversal path components are not exportable: ' . $path);
        }
    }
}
