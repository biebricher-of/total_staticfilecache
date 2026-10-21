<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$origin = 'http://' . $_SERVER['HTTP_HOST'];
file_put_contents(getenv('EXPORTER_REQUEST_LOG'), json_encode([
    'uri' => $_SERVER['REQUEST_URI'],
    'cookie' => $_SERVER['HTTP_COOKIE'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'cache_control' => $_SERVER['HTTP_CACHE_CONTROL'] ?? null,
    'pragma' => $_SERVER['HTTP_PRAGMA'] ?? null,
    'crawler' => $_SERVER['HTTP_X_T3CRAWLER'] ?? null,
]) . "\n", FILE_APPEND);

$loc = static fn (string $url): string => htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$set = static function (array $urls) use ($loc): string {
    return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . implode('', array_map(static fn ($url) => '<url><loc>' . $loc($url) . '</loc></url>', $urls)) . '</urlset>';
};
$index = static function (array $urls) use ($loc): string {
    return '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . implode('', array_map(static fn ($url) => '<sitemap><loc>' . $loc($url) . '</loc></sitemap>', $urls)) . '</sitemapindex>';
};

header('Content-Type: application/xml');
switch ($path) {
    case '/site/index.xml':
        echo $index([$origin . '/site/pages.xml?chunk=1&source=test', $origin . '/site/index.xml', $origin . '/site/plain.xml.gz', $origin . '/site/encoded.xml']);
        return;
    case '/site/pages.xml':
        echo $set([$origin . '/site/', $origin . '/site/%C3%BCber/', $origin . '/site/old']);
        return;
    case '/site/plain.xml.gz':
        header('Content-Type: application/gzip');
        echo gzencode($set([$origin . '/site/gzip/']));
        return;
    case '/site/encoded.xml':
        header('Content-Encoding: gzip');
        echo gzencode($set([$origin . '/site/transport/']));
        return;
    case '/site/external.xml':
        echo $index(['http://other.invalid/site/child.xml']);
        return;
    case '/site/outside.xml':
        echo $index([$origin . '/outside.xml']);
        return;
    case '/site/query-page.xml':
        echo $set([$origin . '/site/final/?personal=1']);
        return;
    case '/site/xxe.xml':
        echo '<!DOCTYPE urlset [<!ENTITY leak SYSTEM "http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/xxe-sentinel">]><urlset><url><loc>&leak;</loc></url></urlset>';
        return;
    case '/site/local-xxe.xml':
        echo '<!DOCTYPE urlset [<!ENTITY leak SYSTEM "file:///etc/passwd">]><urlset><url><loc>&leak;</loc></url></urlset>';
        return;
    case '/site/malformed.xml':
        echo '<urlset><url><loc>broken';
        return;
    case '/site/duplicate-loc.xml':
        echo '<urlset><url><loc>/site/</loc><loc>/site/final/</loc></url></urlset>';
        return;
    case '/site/empty.xml':
        echo '<urlset/>';
        return;
    case '/site/sitemap-redirect':
        header('Location: pages.xml?redirected=1', true, 302);
        return;
    case '/site/sitemap-escape':
        header('Location: http://other.invalid/site/child.xml', true, 302);
        return;
    case '/site/sitemap-cookie':
        header('Set-Cookie: harmless=yes');
        echo $set([$origin . '/site/']);
        return;
}

header('Content-Type: text/html; charset=UTF-8');
if (preg_match('~^/site/many-redirects/(\d+)$~', $path, $match)) {
    header('Location: /site/many-redirects/' . ((int) $match[1] + 1), true, 302);
    return;
}
switch ($path) {
    case '/site/slash':
        header('Location: slash/', true, 301);
        return;
    case '/site/alias-one':
    case '/site/alias-two':
        header('Location: /site/slash', true, 302);
        return;
    case '/site/parent-alias':
        header('Location: /site/child-terminal', true, 302);
        return;
    case '/site/old':
        header('Location: final/', true, 301);
        echo 'Redirect body must not be cached';
        return;
    case '/site/cross-origin':
        header('Location: http://other.invalid/site/final/', true, 302);
        return;
    case '/site/cross-port':
        header('Location: http://fixture.test:1/site/final/', true, 302);
        return;
    case '/site/cross-scheme':
        header('Location: https://' . $_SERVER['HTTP_HOST'] . '/site/final/', true, 302);
        return;
    case '/site/outside':
        header('Location: /outside/', true, 302);
        return;
    case '/site/redirect-query':
        header('Location: /site/final/?session=1', true, 302);
        return;
    case '/site/redirect-traversal':
        header('Location: /site/%2e%2e/outside/', true, 302);
        return;
    case '/site/login-redirect':
        header('Location: /site/login/', true, 302);
        return;
    case '/site/cookie-redirect':
        header('Set-Cookie: session=secret; HttpOnly');
        header('Location: /site/final/', true, 302);
        return;
    case '/site/cookie':
        header('Set-Cookie: harmless=yes');
        break;
    case '/site/unauthorized':
        http_response_code(401);
        break;
    case '/site/error':
        http_response_code(500);
        break;
    case '/site/not-found':
        http_response_code(404);
        break;
    case '/site/plain':
        header('Content-Type: text/plain');
        echo '<html>Not actually HTML content-type</html>';
        return;
    case '/site/loop':
        header('Location: /site/loop', true, 302);
        return;
    case '/site/large':
        echo str_repeat('X', 16777217);
        return;
    case '/site/slow':
        sleep(2);
        break;
}

echo '<!doctype html><html lang="de"><head><title>Snapshot</title></head><body>Grüße 🌍<!-- full document --><section id="plugin">rendered anonymously: 7</section><script>window.plugin={"html":"<b>unmodified</b>"};</script></body></html>';
