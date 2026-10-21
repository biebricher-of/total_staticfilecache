<?php

declare(strict_types=1);

namespace TotalStaticFileCache;

/** Generate a replacement document-root .htaccess, not an addition to TYPO3's PHP router. */
function apacheRules(array $profile, string $origin, string $cacheDirectory, string $mountPath): string
{
    $parts = parse_url($origin);
    if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
        throw new \InvalidArgumentException('Invalid public origin.');
    }
    if (!preg_match('~\A[a-zA-Z0-9_/-]+\z~D', $cacheDirectory)
        || str_contains($cacheDirectory, '..') || str_starts_with($cacheDirectory, '/')) {
        throw new \InvalidArgumentException('Cache directory must be a safe relative directory.');
    }
    $pages = $profile['pages'] ?? null;
    if (!is_array($pages) || $pages === []) {
        throw new \InvalidArgumentException('Apache generation requires the successfully exported page URLs in profile[pages].');
    }
    $mount = rtrim($mountPath, '/');
    $authority = strtolower($parts['host']);
    $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;
    $hostPattern = preg_quote($authority, '~') . ($port === $defaultPort ? '(?::' . $port . ')?' : ':' . $port);
    $lines = [
        '# Generated full-static configuration for ' . $profile['label'],
        '# REPLACE the TYPO3 document-root .htaccess; do not append this below its front controller.',
        '# Apache >= 2.4, mod_rewrite, mod_mime, AllowOverride FileInfo Options Indexes required.',
        '# For a subdirectory installation, put this file in that installation directory.',
        '# No public PHP, including backend. Rebuild via a separate protected rendering endpoint.',
        'Options -Indexes -MultiViews -ExecCGI',
        'DirectoryIndex disabled',
        'DirectorySlash Off',
        'AcceptPathInfo Off',
        'ErrorDocument 403 "Forbidden"',
        'ErrorDocument 404 "Not found"',
        'ErrorDocument 405 "Method not allowed"',
        'ErrorDocument 500 "Internal server error"',
        'AddDefaultCharset Off',
        '<FilesMatch "^index(?:\\.html)?$">',
        '    ForceType text/html',
        '</FilesMatch>',
        'RewriteEngine On',
        '',
        '# Pin the hostname; forwarded host headers are deliberately not trusted.',
        'RewriteCond %{HTTP_HOST} !^' . $hostPattern . '$ [NC]',
        'RewriteRule ^ - [R=404,END]',
        '',
        '# No state-changing requests or query variants of frozen pages.',
        'RewriteCond %{REQUEST_METHOD} !^(GET|HEAD)$',
        'RewriteRule ^ - [R=405,END]',
        '',
        '# Deny scripts, hidden files, configuration and direct snapshot access.',
        ...array_map(static fn (string $pattern): string => 'RewriteRule "' . $pattern . '" - [R=404,END,NC]', apacheDeniedPaths($cacheDirectory)),
        '',
        '# Exact exported URL -> version-specific cache file; no runtime PHP lookup.',
        '# Query strings on HTML requests are rejected rather than silently sharing a snapshot.',
    ];
    foreach (array_values(array_unique($pages)) as $url) {
        $urlParts = parse_url($url);
        $path = $urlParts['path'] ?? '/';
        if ($mount !== '' && $path !== $mount && !str_starts_with($path, $mount . '/')) {
            throw new \InvalidArgumentException('Exported page is outside the installation mount.');
        }
        // Encode on-disk cache names, not the already URL-encoded installation mount.
        $suffix = '/' . trim($cacheDirectory, '/') . '/' . cacheFile($profile, $url);
        $target = $mount . implode('/', array_map('rawurlencode', explode('/', $suffix)));
        // A literal percent must not be interpreted as a RewriteCond %N backreference.
        $target = str_replace('%', '\\%', $target);
        $lines[] = 'RewriteCond %{QUERY_STRING} ^$';
        $lines[] = 'RewriteCond %{THE_REQUEST} "^[A-Z]+\\x20' . apacheRegex($path) . '(?:\\x20|\\?)"';
        $lines[] = 'RewriteRule ^ "' . $target . '" [NE,END]';
    }
    array_push($lines,
        '',
        '# Reuse only public asset trees, with a non-executable extension allowlist.',
        '# Asset version query strings are allowed; the underlying file must exist.',
        'RewriteCond %{REQUEST_FILENAME} -f',
        'RewriteRule "^(?:(?:fileadmin|uploads|_assets)/|typo3temp/(?:assets|compressor|GB|pics|temp)/|typo3conf/ext/[^/]+/(?:Resources/Public|res|pi[0-9]*/res)/|typo3/sysext/[^/]+/Resources/Public/|typo3/sysext/cms/tslib/media/).+\\.(?:css|js|mjs|map|png|jpe?g|gif|svg|webp|avif|ico|woff2?|ttf|otf|eot|pdf|txt|xml|json|webmanifest|mp[34]|webm|ogg|wav|zip|docx?|xlsx?|pptx?|csv)$" - [END,NC]',
        'RewriteCond %{REQUEST_FILENAME} -f',
        'RewriteRule "^typo3temp/(?:javascript_[a-f0-9]+\\.js|stylesheet_[a-f0-9]+\\.css)$" - [END,NC]',
        'RewriteCond %{REQUEST_FILENAME} -f',
        'RewriteRule "^(?:robots\\.txt|sitemap(?:[0-9_-]*)\\.xml|favicon\\.ico|manifest\\.webmanifest)$" - [END,NC]',
        '',
        '# Missing pages/assets never reach index.php or another inherited front controller.',
        'RewriteRule ^ - [R=404,END]',
        ''
    );
    return implode("\n", $lines);
}

/** Shared by the exporter and per-directory Apache rules, after URL decoding and mount removal. */
function apacheDeniedPaths(string $cacheDirectory): array
{
    return [
        '(^|/)\\.',
        '^(?:vendor|typo3_src|config|var|src|tests|node_modules)(?:/|$)',
        '\\.(?:php[0-9]*|phtml|pht|phar|phps|cgi|fcgi|pl|py|rb|sh|shtml|htaccess|ini|sql|ya?ml|twig|bak|log)(?:[./;]|$)',
        '^' . preg_quote(trim($cacheDirectory, '/'), '~') . '(?:/|$)',
    ];
}

/** Escape Apache config syntax as well as regular-expression metacharacters. */
function apacheRegex(string $value): string
{
    return str_replace([' ', '"', '#', '%'], ['\\x20', '\\x22', '\\x23', '\\x25'], preg_quote($value, '~'));
}
