# total_staticfilecache

Export anonymous, fully rendered TYPO3 pages—including normally uncached plugins—to StaticFileCache-compatible **plain HTML file paths**. Serve the snapshots directly through Apache without a public PHP frontend or backend.

This is an external HTTP snapshot tool, not a TYPO3 extension. It does not change a plugin's cacheability, bootstrap TYPO3, or depend on TYPO3's PHP version. Version-specific CLI entry scripts share one implementation.
This independent project targets the cache layouts of the [StaticFileCache TYPO3 extension](https://github.com/lochmueller/staticfilecache). See that upstream project for the extension itself; this tool is not an official part of it.

Licensed under [GNU GPL version 3.0](LICENSE) (`GPL-3.0-only`).

## Requirements

- **Exporter:** PHP 8.2+ CLI with cURL, DOM/libxml and zlib. Run on a separate modern machine if the legacy TYPO3 host cannot run this PHP version.
- **Public server:** Apache 2.4.10+ with `mod_rewrite`, `mod_mime`, `mod_dir`, `mod_headers`, and `.htaccess` overrides for FileInfo, Options and Indexes. Apache 2.2 is not supported, even for TYPO3 4.7.
- A functioning anonymous rendering endpoint for the source site, accessible during exports.
- Human review of the rendered snapshots before publication.

## Export

Use the entry script matching the installed StaticFileCache major. **TYPO3 14 uses `staticfilecache-v16.php`** (16 is the extension major, not the TYPO3 major). See [COMPATIBILITY.md](COMPATIBILITY.md) for the source-verified version matrix and historical `nc_staticfilecache` naming.

### 1. Export from sitemaps

Supply one or more **absolute sitemap URLs**. No `--base-url` is needed for a root-mounted installation:

```sh
php staticfilecache-v16.php \
  --sitemap=https://www.example.org/sitemap.xml \
  --sitemap=https://www.example.de/sitemap.xml \
  --output=/tmp/instance-static-release
```

Repeat `--sitemap` for the domains/languages of **one TYPO3 instance sharing one public cache directory**. Sitemap indexes are expanded recursively. Include every sitemap you intend to publish: the tool does not follow HTML links.

Without `--base-url`, the first sitemap defines the primary origin and the installation prefix defaults to `/`. The sitemap's directory is **not** inferred as an installation prefix. For a subdirectory installation, add `--base-url=https://www.example.org/site`, even when exporting only from sitemaps. With that explicit base, relative sitemap arguments are also accepted.

### 2. Export from URL-list files

Supply `--base-url` with `--urls`, even if every URL in the file is absolute. It defines the trusted primary origin, installation prefix and how relative page URLs are resolved:

```sh
php staticfilecache-v16.php \
  --base-url=https://www.example.org \
  --urls=pages.txt \
  --output=/tmp/list-static-release
```

Example `pages.txt`:

```text
https://www.example.org/
/contact/
/products/
```

URL lists contain one absolute or site-relative URL per line; blank lines and `#` comments are allowed. Repeat `--urls` for additional files. You may combine `--urls` and `--sitemap` in one invocation; `--base-url` remains required whenever `--urls` is used. URL-list contents do not grant trust to additional domains: supply their sitemap URLs explicitly when exporting a multi-domain instance.

### Shared output and trusted domains

Explicitly supplied sitemap URLs and the optional base URL define the trusted origins (scheme, hostname and port). All use the same installation prefix. Child sitemaps, page URLs and redirects may cross between these origins, but XML content and redirects never grant trust to another origin. Every explicitly supplied sitemap must be safe and within that prefix, even if another sitemap already references it. One export produces one cache tree, one manifest and one `.htaccess` for all these domains. Identical paths on different domains remain separate snapshots; the rules match each page's own hostname and port. The manifest's `origin` is the explicit base origin or, without one, the first sitemap origin; `origins` lists all trusted origins, including domains with no successful pages.

For TYPO3 installed below a URL prefix, include it in the base URL, e.g. `--base-url=https://www.example.org/site`. The output cache directory is relative to **that installation's public web directory**; exported page paths retain the URL prefix where the historical cache format does so.

The output directory must be new or empty. Exporting directly into a live installation is deliberately refused. **Individual page failures still produce `full-static.htaccess`**, mapping only successfully captured pages, and a manifest with `complete: false` and failure details. The command reports those failures and **exits 1**; a complete export exits 0. Review incomplete exports yourself before deciding whether to deploy: failed or missing pages return 404, never PHP fallback. If every capture fails, the generated rules serve no HTML pages.

Fatal configuration, unsafe source URLs or redirects, cache collisions, and file/publication errors still stop the export without publishing new deployment rules. Individual files are published atomically without replacing existing files or symlinks. Partial output after a fatal error is not a publishable release. The exporter never deploys anything automatically.

Output:

```text
example-static-release/
├── full-static.htaccess       # replacement public .htaccess, including for incomplete exports
├── manifest.json             # export report; do not publish
└── typo3temp/...              # version-specific cache directory and HTML files
```

Only plain HTML is exported: no `.php` generators, gzip/Brotli siblings, expiry redirects, TYPO3 cache database records, tags, or scheduler queue entries. This matches the **HTML delivery layout**, not a managed extension-cache installation. The generated rules use an exact URL-to-file map, so they do not rely on a particular TYPO3 front-controller routing template.
The fresh cache directory includes a minimal `.htaccess` forcing saved responses to `text/html`, including old layouts that retain `.xml`, `.rss` or `.sfc` filenames. Copy this file with the rest of the cache; it contains no expiry redirects or PHP fallback.

## Switch to static-only delivery

1. Export while the source still renders through PHP. Inspect the manifest's `complete` flag and failures, plus sample pages, plugin output, every language and every instance domain. Decide explicitly whether any missing pages are acceptable before publishing an incomplete export.
2. Test on staging with equivalent hostname/port routing for **every** instance domain. Verify that all referenced assets still exist and are permitted by the generated asset rules.
3. Back up the installation's existing `.htaccess` and cache directory outside the public web root.
4. Publish the **complete fresh cache directory** from the output. Do not merge it into a live extension cache containing old `.htaccess` expiry rules.
5. Replace the installation's public `.htaccess` with `full-static.htaccess`. **Do not append it below TYPO3's existing rewrite rules.** Use a controlled deployment/maintenance window or an atomic release switch; publishing the rules and files separately is not atomic.
6. Verify the public homepage, deep links and assets. `/index.php`, `/typo3/`, unknown pages, and direct cache URLs must not reach PHP. Roll back the old `.htaccess` and cache together if necessary.

For legacy installations, the public directory may be the project root. For Composer installations it is the configured document root, commonly `public/` (sometimes `web/`), **not** the directory containing `composer.json`. TYPO3's major alone cannot determine the document root.

The generated rules permit GET/HEAD, reject other methods with 405, return 404 for unexported pages and direct script requests, disable directory listings and MultiViews, and use inline errors rather than PHP error documents. They permit existing public assets in `fileadmin/`, `uploads/`, `typo3temp/assets/`, `_assets/`, `typo3conf/ext/*/Resources/Public/`, and `typo3/sysext/*/Resources/Public/`, restricted to common static extensions, plus selected root files such as `robots.txt`. Review these allowlists for your installation; legacy extensions with unconventional asset paths may need explicit additions. Asset version query strings remain allowed.

Legacy public paths are also covered: `typo3temp/compressor/`, `typo3temp/GB/`, `typo3temp/pics/`, `typo3temp/temp/`, generated `typo3temp/javascript_*.js` and `stylesheet_*.css`, extension `res/` directories, and the old CMS `tslib/media/` tree. The extension allowlist still applies. `AddDefaultCharset Off` leaves encoding declarations in the saved HTML authoritative; review pages that relied solely on an HTTP charset header.

Cached HTML responses include `X-Total-StaticFileCache: HIT` (also for HEAD and conditional 304 responses). Assets and errors do not receive this header. It is set in the generated cache-directory `.htaccess`; deploy that file with the snapshots. No browser cache lifetime is set or changed.

**Server configuration matters:** `.htaccess` cannot undo every virtual-host alias, parent rewrite, handler mapping or external `ErrorDocument`. For the strongest no-PHP boundary, use a dedicated static virtual host without PHP/FastCGI handlers, CGI, SSI, aliases to the backend, or inherited PHP routing. Audit asset-directory symlinks and nested `.htaccess` files. The exporter cannot verify your production server configuration.

## Rebuilds

Static-only delivery cannot render updates. Keep an independently protected PHP rendering route, with no public access, and preserve the public URL's scheme, hostname, port and path during requests. If the renderer uses another IP:

```sh
php staticfilecache-v13.php \
  --base-url=https://www.example.org \
  --urls=urls.txt \
  --resolve=www.example.org:443:192.0.2.10 \
  --output=/tmp/example-static-next
```

TLS is still verified against the public hostname. Protect that rendering endpoint with network/firewall restrictions. Do not change the public hostname to an unrelated internal hostname, because that changes historical cache keys. Ensure the renderer bypasses previously published snapshots, otherwise the exporter can only capture stale HTML.

Requests send `Cache-Control: no-cache`, `Pragma: no-cache`, and `X-T3Crawler: 1`. These bypass some stock cache rules but are **not a universal rendering guarantee**: older rules (notably the inspected v3 rules) may ignore them. Disable static-cache delivery on the protected renderer. No cookies or authorization credentials are sent.

Cookie-setting responses are rejected by default. Legacy TYPO3 or anonymous plugins may start a session even for public content. After checking that their HTML is safe for everyone to share, use `--allow-set-cookie` to accept these responses. Cookies are still **never sent, persisted or replayed**; each request remains anonymous. The manifest records this opt-in. Do not use it for personalized, authenticated or session-dependent content.

Disable StaticFileCache's writers, cache-clearing integration, boost jobs and expiry redirects for the published directory. This tool owns the snapshots; TYPO3 does not track them. Rebuild into a new directory, review, and replace the whole release. Removed pages disappear only when you replace rather than merge the old release.

## What a frozen site cannot do

- Forms, searches, logins, carts, APIs and server-side personalization stop working unless redesigned to use a separate service. Exporting their HTML does not export their server-side behavior.
- Everybody sees the same anonymous snapshot. Never export authenticated, session-specific or private data.
- Pages using query-string variants are rejected rather than silently colliding. Legacy `index.php?id=...` sites need speaking URLs before this exporter can represent them in the ordinary cache layouts.
- The generated rules match exact exported paths. A bare empty query (`/news/?`) is equivalent to `/news/`, but non-empty HTML query strings return 404. A missing trailing slash (`/news` versus exported `/news/`) is a different path and returns 404; source redirect aliases are not reproduced. Audit inbound links before switching.
- Redirect responses are not exported as redirects; only terminal rendered pages can become snapshots. Include the canonical URLs in your inputs.
- HTML is saved without rewriting asset URLs. Existing images, CSS, JS, downloads and external services must remain available. No standalone asset mirroring is performed.
- HTTP response headers are not replayed. Check CSP, cookies, redirects, language/content negotiation and caching headers separately. A frozen CSP nonce must not be treated as a fresh per-request nonce.
- A cookie-free HTTP 200 response is not proof of correct rendering. Review content: soft 404s, maintenance screens and login screens may look successful to an HTTP crawler.

## Verification

The test suite uses synthetic rendered plugin content, recursive sitemaps, failure fixtures, and a real Apache server; it does not execute every historical TYPO3 release.

```sh
# With a suitable local PHP runtime:
php tests/profiles.php
php tests/exporter.php

# Apache integration (Python 3 + Docker):
# php:8.3-cli must have the exporter extensions installed.
python3 tests/apache.py
```

Verified locally on PHP 8.2, 8.3 and 8.4: 623 exporter checks and 270 profile/path checks on each runtime. All 15 real version entry scripts were also exercised against an HTTP fixture. Apache 2.4.69 passed 4588 checks across all profiles at root, subdirectory, encoded-space and Unicode mounts, including hostname-isolated mappings, trusted cross-domain redirects, and real v16 incomplete/all-failed multisite exports. The documented Apache minimum is based on required directive availability, not a live test of obsolete Apache builds.
Compatibility is based on tagged upstream source, not a claim that obsolete TYPO3 releases are safe to expose publicly.
