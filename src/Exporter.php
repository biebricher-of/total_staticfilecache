<?php

declare(strict_types=1);

namespace TotalStaticFileCache;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Standalone entry point; the wrapper supplies cacheFile() and apacheRules(). */
function run(array $profile, array $argv): int
{
    try {
        $options = Exporter::options($argv);
        if (isset($options['help'])) {
            fwrite(STDOUT, Exporter::help());
            return 0;
        }
        if (!function_exists('curl_init') || !class_exists(DOMDocument::class) || !function_exists('gzdecode')) {
            throw new RuntimeException('PHP extensions curl and dom, plus zlib, are required (PHP 8.2+).');
        }
        (new Exporter($profile, $options))->export();
        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'Export failed: ' . $error->getMessage() . "\n");
        return 1;
    }
}

/** Internal engine: no TYPO3 bootstrap, browser state, or HTML transformations. */
final class Exporter
{
    private const MAX_BYTES = 16777216;
    private const MAX_SITEMAPS = 1000;
    private const MAX_PAGES = 50000;
    private const MAX_REDIRECTS = 10;

    private string $origin;
    private string $mount;
    private string $output;
    private string $cacheDirectory;
    /** URL => relative cache path, including paths reserved before the first page request. */
    private array $mappings = [];
    private array $pathOwners = [];
    private array $directoryUses = [];
    private array $completed = [];
    private array $redirectTargets = [];

    public function __construct(private array $profile, private array $options)
    {
        foreach (['base-url', 'output'] as $required) {
            if (!isset($options[$required])) {
                throw new InvalidArgumentException("Missing --$required. Use --help for usage.");
            }
        }
        if (empty($options['urls']) && empty($options['sitemap'])) {
            throw new InvalidArgumentException('Supply at least one --urls or --sitemap source.');
        }
        $base = self::absoluteUrl($options['base-url'], false);
        $this->origin = $base['origin'];
        $this->mount = rtrim($base['path'], '/');
        $this->cacheDirectory = self::relativePath($options['cache-dir'] ?? $profile['cacheDirectory']);
        if (!preg_match('~\A[a-zA-Z0-9_/-]+\z~D', $this->cacheDirectory)) {
            throw new InvalidArgumentException('Cache directory must be a safe relative directory using letters, digits, underscores, hyphens and slashes.');
        }
        if (in_array(explode('/', $this->cacheDirectory)[0], ['manifest.json', 'full-static.htaccess'], true)) {
            throw new InvalidArgumentException('Cache directory conflicts with exporter metadata.');
        }
        $this->output = self::outputPath($options['output']);
    }

    public static function help(): string
    {
        return <<<'HELP'
Anonymous full-HTML snapshot exporter (PHP 8.2+; curl, dom and zlib required to export)

Usage: php <wrapper.php> --base-url=https://example.com[/subdir]
       --urls=urls.txt [--urls=more.txt] [--sitemap=https://example.com/sitemap.xml]
       --output=/new/empty-staging-directory [options]

  --base-url=URL       Single allowed HTTP(S) origin and optional site mount
  --urls=FILE          UTF-8 URL list; blank lines and # comments ignored (repeatable)
  --sitemap=URL        Sitemap or sitemap index (repeatable, recursive)
  --output=DIR         Absent or empty staging directory; no symlink components
  --cache-dir=PATH     Relative document-root cache directory (profile default)
  --resolve=HOST:PORT:ADDRESS  cURL DNS override (repeatable)
  --timeout=SECONDS    Per-request timeout, 1..300 (default 30)
  --allow-set-cookie   Accept cookie-setting anonymous responses; cookies are NEVER sent/replayed
  --help               Show help without requiring curl or XML extensions

Requests are anonymous, without cookies or credentials; TLS is verified.
Only same-origin, same-mount redirects are followed. Query/fragment page URLs,
cookie-setting responses (unless explicitly allowed), authentication paths, and unsafe/colliding paths fail.
Redirect aliases are not exported; only the terminal HTML page is stored.
Limits: 16 MiB per response, 1000 sitemaps, 50000 pages, 10 redirects.
On success: full-static.htaccess and manifest.json accompany the cache tree.
Deploy cache files and the reviewed rules only; do not deploy manifest.json.
A failed export may leave partial staging files but never a ready rules snippet.

HELP;
    }

    public static function options(array $argv): array
    {
        $options = ['urls' => [], 'sitemap' => [], 'resolve' => [], 'timeout' => 30, 'allow-set-cookie' => false];
        $repeatable = ['urls', 'sitemap', 'resolve'];
        $seen = [];
        for ($i = 1; $i < count($argv); ++$i) {
            $argument = $argv[$i];
            if ($argument === '--allow-set-cookie') {
                $options['allow-set-cookie'] = true;
                continue;
            }
            if ($argument === '--help') {
                $options['help'] = true;
                continue;
            }
            if (!str_starts_with($argument, '--')) {
                throw new InvalidArgumentException("Unexpected argument: $argument");
            }
            $pair = explode('=', substr($argument, 2), 2);
            $key = $pair[0];
            if (!in_array($key, ['base-url', 'urls', 'sitemap', 'output', 'cache-dir', 'resolve', 'timeout'], true)) {
                throw new InvalidArgumentException("Unknown option: --$key");
            }
            $value = $pair[1] ?? ($argv[++$i] ?? '');
            if ($value === '' || str_starts_with($value, '--')) {
                throw new InvalidArgumentException("Missing value for --$key");
            }
            if (in_array($key, $repeatable, true)) {
                $options[$key][] = $value;
            } else {
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException("Option --$key may only be supplied once.");
                }
                $seen[$key] = true;
                $options[$key] = $value;
            }
        }
        if (!preg_match('/^[0-9]+$/D', (string) $options['timeout']) || (int) $options['timeout'] < 1 || (int) $options['timeout'] > 300) {
            throw new InvalidArgumentException('--timeout must be an integer from 1 to 300.');
        }
        $options['timeout'] = (int) $options['timeout'];
        return $options;
    }

    public function export(): void
    {
        $this->prepareOutput();
        $pages = [];
        $listBase = $this->origin . $this->mount . '/';
        foreach ($this->options['urls'] as $file) {
            if (!is_file($file) || !is_readable($file) || filesize($file) > self::MAX_BYTES) {
                throw new RuntimeException("URL list is unreadable or exceeds 16 MiB: $file");
            }
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                throw new RuntimeException("Cannot open URL list: $file");
            }
            try {
                while (($line = fgets($stream, self::MAX_BYTES + 1)) !== false) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $this->addPage($pages, $this->scopedUrl($line, $listBase, false));
                }
            } finally {
                fclose($stream);
            }
        }

        $queue = [];
        foreach ($this->options['sitemap'] as $url) {
            $queue[] = $this->scopedUrl($url, $listBase, true);
        }
        $visited = [];
        for ($i = 0; $i < count($queue); ++$i) {
            $url = $queue[$i];
            if (isset($visited[$url])) {
                continue;
            }
            if (count($visited) >= self::MAX_SITEMAPS) {
                throw new RuntimeException('Sitemap limit exceeded (1000).');
            }
            $visited[$url] = true;
            $response = $this->request($url, true);
            $visited[$response['url']] = true;
            foreach ($this->sitemapEntries($response['body']) as [$kind, $location]) {
                $target = $this->scopedUrl($location, $response['url'], $kind === 'sitemap');
                if ($kind === 'sitemap') {
                    if (count($queue) >= self::MAX_SITEMAPS * 2) {
                        throw new RuntimeException('Sitemap reference limit exceeded (2000).');
                    }
                    if (!isset($visited[$target]) && !in_array($target, $queue, true)) {
                        $queue[] = $target;
                    }
                } else {
                    $this->addPage($pages, $target);
                }
            }
        }
        if ($pages === []) {
            throw new RuntimeException('No page URLs found.');
        }
        // Reserve every source mapping before fetching any page, including prefix collisions.
        foreach (array_keys($pages) as $url) {
            $this->reserve($url);
        }

        $failures = [];
        foreach (array_keys($pages) as $url) {
            if (isset($this->completed[$url])) {
                continue;
            }
            try {
                $response = $this->request($url, false);
                $terminal = $response['url'];
                if (isset($this->completed[$terminal])) {
                    continue;
                }
                $path = $this->reserve($terminal);
                $this->writeFile($this->cacheDirectory . '/' . $path, $response['body']);
                $this->completed[$terminal] = [
                    'url' => $terminal,
                    'file' => $this->cacheDirectory . '/' . $path,
                    'bytes' => strlen($response['body']),
                    'sha256' => hash('sha256', $response['body']),
                ];
            } catch (Throwable $error) {
                $failures[] = ['url' => $url, 'error' => $error->getMessage()];
                fwrite(STDERR, "$url: {$error->getMessage()}\n");
            }
        }
        $manifest = [
            'profile' => $this->profile['id'],
            'typo3' => $this->profile['typo3'] ?? [],
            'origin' => $this->origin,
            'mountPath' => $this->mount,
            'cacheDirectory' => $this->cacheDirectory,
            'allowSetCookieResponses' => $this->options['allow-set-cookie'],
            'createdAt' => gmdate('c'),
            'complete' => $failures === [],
            'pages' => array_values($this->completed),
            'failures' => $failures,
        ];
        $this->writeFile('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        if ($failures !== []) {
            throw new RuntimeException(count($failures) . ' page(s) failed; no full-static.htaccess generated.');
        }
        // Older layouts can retain .xml/.rss/.sfc filenames even for HTML responses.
        $this->writeFile($this->cacheDirectory . '/.htaccess', "ForceType text/html\nAddDefaultCharset Off\nOptions -Indexes -MultiViews -ExecCGI\n");
        $rulesProfile = $this->profile;
        $rulesProfile['pages'] = array_keys($this->completed);
        $rules = apacheRules($rulesProfile, $this->origin, $this->cacheDirectory, $this->mount);
        $this->writeFile('full-static.htaccess', $rules);
        fwrite(STDOUT, 'Exported ' . count($this->completed) . " HTML page(s) to {$this->output}\n");
        fwrite(STDOUT, "Ready for review: {$this->output}/full-static.htaccess (do not deploy manifest.json).\n");
    }

    private function addPage(array &$pages, string $url): void
    {
        $pages[$url] = true;
        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('Page limit exceeded (50000).');
        }
    }

    private function reserve(string $url): string
    {
        $path = $this->mappings[$url] ?? self::relativePath(cacheFile($this->profile, $url));
        if (($this->pathOwners[$path] ?? null) === $url) {
            return $path;
        }
        if (isset($this->pathOwners[$path]) && $this->pathOwners[$path] !== $url) {
            throw new RuntimeException("Cache path collision: $url and {$this->pathOwners[$path]} map to $path");
        }
        if (isset($this->directoryUses[$path])) {
            throw new RuntimeException("Cache file/directory collision: $path is already reserved as a directory");
        }
        $ancestors = [];
        $parent = dirname($path);
        while ($parent !== '.') {
            if (isset($this->pathOwners[$parent])) {
                throw new RuntimeException("Cache file/directory collision: $path and $parent");
            }
            $ancestors[] = $parent;
            $parent = dirname($parent);
        }
        foreach ($ancestors as $parent) {
            $this->directoryUses[$parent] = ($this->directoryUses[$parent] ?? 0) + 1;
        }
        $this->pathOwners[$path] = $url;
        return $this->mappings[$url] = $path;
    }

    /** Redirect aliases never occupy cache files, even when /about and /about/ map identically. */
    private function releaseAlias(string $url): void
    {
        $path = $this->mappings[$url];
        if (isset($this->completed[$url])) {
            return;
        }
        unset($this->pathOwners[$path]);
        $parent = dirname($path);
        while ($parent !== '.') {
            if (--$this->directoryUses[$parent] === 0) {
                unset($this->directoryUses[$parent]);
            }
            $parent = dirname($parent);
        }
    }

    private function request(string $url, bool $sitemap): array
    {
        $seen = [];
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; ++$redirect) {
            if (isset($seen[$url])) {
                throw new RuntimeException("Redirect cycle: $url");
            }
            $seen[$url] = true;
            if (!$sitemap) {
                if (isset($this->completed[$url])) {
                    return ['url' => $url, 'body' => '']; // Already stored; caller skips it.
                }
                if (isset($this->redirectTargets[$url])) {
                    $url = $this->redirectTargets[$url];
                    continue;
                }
                $this->reserve($url);
                $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
                if (preg_match('~/(?:login|log-in|signin|sign-in|typo3)(?:/|$)~', $path)) {
                    throw new RuntimeException("Authentication path cannot be exported: $url");
                }
            }
            $body = '';
            $headers = [];
            $tooLarge = false;
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Cannot initialize cURL.');
            }
            try {
                curl_setopt_array($handle, [
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => $this->options['timeout'],
                    CURLOPT_CONNECTTIMEOUT => min(10, $this->options['timeout']),
                    CURLOPT_ENCODING => '',
                    CURLOPT_PROXY => '', // Do not leak anonymous requests through environment proxies.
                    CURLOPT_NETRC => CURL_NETRC_IGNORED,
                    CURLOPT_USERAGENT => 'TotalStaticFileCache/1.0 anonymous snapshot exporter',
                    CURLOPT_HTTPHEADER => ['Accept: ' . ($sitemap ? 'application/xml,text/xml,*/*;q=0.1' : 'text/html'), 'Cookie:', 'Authorization:', 'Cache-Control: no-cache', 'Pragma: no-cache', 'X-T3Crawler: 1'],
                    CURLOPT_RESOLVE => $this->options['resolve'],
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                        if (str_starts_with($line, 'HTTP/')) {
                            $headers = [];
                        } elseif (str_contains($line, ':')) {
                            [$name, $value] = explode(':', $line, 2);
                            $headers[strtolower(trim($name))][] = trim($value);
                        }
                        return strlen($line);
                    },
                    CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                        if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                            $tooLarge = true;
                            return 0;
                        }
                        $body .= $chunk;
                        return strlen($chunk);
                    },
                ]);
                $ok = curl_exec($handle);
                $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                if ($ok === false) {
                    throw new RuntimeException($tooLarge ? 'Response exceeds 16 MiB.' : 'HTTP transport error: ' . curl_error($handle));
                }
            } finally {
                curl_close($handle);
            }
            if (isset($headers['set-cookie']) && !$this->options['allow-set-cookie']) {
                throw new RuntimeException('Set-Cookie response refused. Review anonymous session content, then use --allow-set-cookie if it is safe to freeze; cookies are never sent or replayed.');
            }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($redirect === self::MAX_REDIRECTS || count($headers['location'] ?? []) !== 1) {
                    throw new RuntimeException('Too many redirects or missing/ambiguous Location header.');
                }
                $target = $this->scopedUrl($headers['location'][0], $url, $sitemap);
                if (!$sitemap) {
                    $this->releaseAlias($url);
                    $this->redirectTargets[$url] = $target;
                }
                $url = $target;
                continue;
            }
            if ($status !== 200) {
                throw new RuntimeException("Expected HTTP 200, received $status at $url");
            }
            if (!$sitemap) {
                $type = strtolower(trim(explode(';', $headers['content-type'][0] ?? '')[0]));
                if ($type !== 'text/html') {
                    throw new RuntimeException("Expected text/html, received '$type' at $url");
                }
            } elseif (str_starts_with($body, "\x1f\x8b")) {
                $decoded = @gzdecode($body, self::MAX_BYTES + 1);
                if ($decoded === false || strlen($decoded) > self::MAX_BYTES) {
                    throw new RuntimeException('Invalid or oversized gzip sitemap.');
                }
                $body = $decoded;
            }
            return ['url' => $url, 'body' => $body];
        }
        throw new RuntimeException('Redirect limit exceeded.');
    }

    private function sitemapEntries(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null) {
                throw new RuntimeException('Invalid sitemap XML (DTDs and external entities are forbidden).');
            }
            $root = $document->documentElement;
            if ($root === null || !in_array($root->localName, ['urlset', 'sitemapindex'], true)
                || !in_array($root->namespaceURI, [null, '', 'http://www.sitemaps.org/schemas/sitemap/0.9'], true)) {
                throw new RuntimeException('Expected a sitemap urlset or sitemapindex.');
            }
            $kind = $root->localName === 'sitemapindex' ? 'sitemap' : 'url';
            $entries = [];
            foreach ($root->childNodes as $item) {
                if (!$item instanceof DOMElement || $item->localName !== $kind || $item->namespaceURI !== $root->namespaceURI) {
                    continue;
                }
                $locations = [];
                foreach ($item->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === 'loc' && $child->namespaceURI === $root->namespaceURI) {
                        foreach ($child->childNodes as $value) {
                            if ($value instanceof DOMElement || $value->nodeType === XML_ENTITY_REF_NODE) {
                                throw new RuntimeException('Sitemap loc must contain plain URL text.');
                            }
                        }
                        $locations[] = trim($child->textContent);
                    }
                }
                if (count($locations) !== 1 || $locations[0] === '') {
                    throw new RuntimeException('Each sitemap entry needs exactly one non-empty loc.');
                }
                $entries[] = [$kind, $locations[0]];
                if (count($entries) > self::MAX_PAGES) {
                    throw new RuntimeException('Sitemap entry limit exceeded (50000).');
                }
            }
            return $entries;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function scopedUrl(string $reference, string $base, bool $sitemap): string
    {
        $url = self::resolveUrl($reference, $base);
        $parsed = self::absoluteUrl($url, $sitemap);
        if ($parsed['origin'] !== $this->origin || ($this->mount !== ''
            && $parsed['path'] !== $this->mount && !str_starts_with($parsed['path'], $this->mount . '/'))) {
            throw new InvalidArgumentException("URL escapes the allowed origin/site mount: $url");
        }
        return $parsed['url'];
    }

    /** parse_url handles URL syntax; this policy deliberately rejects ambiguous path encodings. */
    private static function absoluteUrl(string $url, bool $allowQuery): array
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || preg_match('/%(?![0-9a-fA-F]{2})/', $url)) {
            throw new InvalidArgumentException("Unsafe URL: $url");
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || array_key_exists('fragment', $parts)
            || (!$allowQuery && array_key_exists('query', $parts))) {
            throw new InvalidArgumentException("Expected credential-free HTTP(S) URL without " . ($allowQuery ? 'fragment' : 'query/fragment') . ": $url");
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        // ASCII DNS names and bracketed IPv6 only; IDNs must be supplied as punycode.
        if ($host === '' || (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new InvalidArgumentException("Invalid URL host (use punycode for IDNs): $url");
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1) {
            throw new InvalidArgumentException("Invalid URL port: $url");
        }
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        // Sitemap IRIs may contain Unicode; browsers request their percent-encoded URL form.
        $path = preg_replace_callback('/[^\x21-\x7e]/', static fn (array $match): string => rawurlencode($match[0]), $path);
        self::safeUrlPath($path);
        $origin = $scheme . '://' . $host . (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port);
        return ['origin' => $origin, 'path' => $path, 'url' => $origin . $path . (array_key_exists('query', $parts) ? '?' . $parts['query'] : '')];
    }

    private static function safeUrlPath(string $path): void
    {
        $decoded = rawurldecode($path);
        if (!str_starts_with($path, '/') || str_contains($path, '//')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $decoded) || preg_match('/%(?:2f|5c|25)/i', $path)) {
            throw new InvalidArgumentException("Unsafe URL path: $path");
        }
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException("Dot traversal in URL path: $path");
            }
        }
    }

    /** Resolve relative HTTP references using parse_url components, never a URL regex. */
    private static function resolveUrl(string $reference, string $base): string
    {
        if ($reference === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $reference)) {
            throw new InvalidArgumentException('Empty or unsafe URL reference.');
        }
        $parts = parse_url($reference);
        $parent = parse_url($base);
        if ($parts === false || $parent === false) {
            throw new InvalidArgumentException("Invalid URL reference: $reference");
        }
        if (isset($parts['scheme'])) {
            return $reference;
        }
        if (str_starts_with($reference, '//')) {
            return $parent['scheme'] . ':' . $reference;
        }
        $origin = $parent['scheme'] . '://' . $parent['host'] . (isset($parent['port']) ? ':' . $parent['port'] : '');
        $path = $parts['path'] ?? '';
        if ($path === '') {
            $path = $parent['path'] ?? '/';
        } elseif (!str_starts_with($path, '/')) {
            $parentPath = $parent['path'] ?? '/';
            $path = substr($parentPath, 0, (int) strrpos($parentPath, '/') + 1) . $path;
        }
        // Do not normalize away traversal: reject it, including percent-encoded variants.
        return $origin . $path
            . (array_key_exists('query', $parts) ? '?' . $parts['query'] : (!isset($parts['path']) && isset($parent['query']) ? '?' . $parent['query'] : ''))
            . (array_key_exists('fragment', $parts) ? '#' . $parts['fragment'] : '');
    }

    private static function relativePath(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            throw new InvalidArgumentException("Unsafe relative cache path: $path");
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException("Unsafe relative cache path: $path");
            }
        }
        return $path;
    }

    private static function outputPath(string $path): string
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            throw new InvalidArgumentException('Invalid output directory.');
        }
        if (!str_starts_with($path, '/')) {
            $path = getcwd() . '/' . $path;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                throw new InvalidArgumentException('Output directory must not contain parent traversal (..).');
            }
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }
        if ($parts === []) {
            throw new InvalidArgumentException('The filesystem root is not an output directory.');
        }
        return '/' . implode('/', $parts);
    }

    private static function directory(string $path): void
    {
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) {
                throw new RuntimeException("Symlink component refused: $current");
            }
            if (!file_exists($current) && !@mkdir($current, 0755) && !is_dir($current)) {
                throw new RuntimeException("Cannot create directory: $current");
            }
            if (!is_dir($current)) {
                throw new RuntimeException("Not a directory: $current");
            }
        }
    }

    private function prepareOutput(): void
    {
        self::directory($this->output);
        $entries = scandir($this->output);
        if ($entries === false || array_diff($entries, ['.', '..']) !== []) {
            throw new RuntimeException('Output directory must be absent or empty; live files will not be overwritten.');
        }
    }

    private function writeFile(string $relative, string $contents): void
    {
        $relative = self::relativePath($relative);
        $path = $this->output . '/' . $relative;
        self::directory(dirname($path));
        if (is_link($path)) {
            throw new RuntimeException("Symlink file refused: $path");
        }
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException("Cannot exclusively create file: $path");
        }
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($stream, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException("Cannot write file: $path");
                }
                $offset += $written;
            }
        } finally {
            fclose($stream);
        }
    }
}
