# Compatibility and source evidence

There is one entry script for each verified major from `nc_staticfilecache` 2 through StaticFileCache 16. **TYPO3 4.7 uses `nc_staticfilecache-v2.php`.** Version 1 predates the requested starting point and has not been implemented. Major 3 is verified from 3.6.0 onward; earlier 3.x releases are not claimed compatible. The constraints below describe the named representative release, not every point release within a major.

The scripts implement upstream default layouts. For majors 2–9, default `fileTypes=xml,rss` means even `/about.html` normally becomes `/about.html/index.html`; major 9 additionally recognizes `.sfc`. Only HTTP `text/html` responses are exported, even when a URL ends in `.xml` or `.rss`. The exporter supports HTTPS for legacy v2 snapshots using the legacy host-only layout, although the inspected extension's own generation branch supported HTTP only. Exact generated Apache mappings make this possible without restoring PHP fallback.

For major 10+, every page ends in `/index`, including URLs ending in `.html`. Path decoding defaults to off. Custom `fileTypes`, path-building event listeners and `rawurldecodeCacheFileName` settings are not inferred from the source site. Use the default exported layout and its generated rules as a standalone snapshot release, rather than mixing it with the extension's managed cache. `--cache-dir` can override the cache-root directory.

# StaticFileCache version/layout investigation

Research snapshot: 2026-10-21. Sources: official TYPO3 Extension Repository (TER) version history/download, immutable tags/commits of `lochmueller/staticfilecache`, and extension-shipped docs. Compatibility ranges below are metadata, not proof every point release was tested against every core point release.

## Release generations and TYPO3 compatibility

| Extension generation | Representative release | Declared TYPO3 compatibility | Source |
|---|---|---|---|
| `nc_staticfilecache` 1.x | 1.0.0 | not verified from metadata available here | [TER history](https://extensions.typo3.org/extension/nc_staticfilecache) |
| `nc_staticfilecache` 2.x | 2.4.0–2.5.2 | 2.5.2 exact range: >=4.5.0, <=6.2.99; TER records 2.4.0, 2.4.1, 2.5.1, 2.5.2 compatible with TYPO3 4.5 LTS | [TER 2.5.2 history](https://extensions.typo3.org/extension/nc_staticfilecache#v2.5.2), [official 2.5.2 ZIP](https://extensions.typo3.org/extension/download/nc_staticfilecache/2.5.2/zip) |
| `nc_staticfilecache` 3.x / maintained repo 3.x | 3.6.0 | 6.2.0–7.99.99 | [tag commit `69e6844`](https://github.com/lochmueller/staticfilecache/tree/69e68449dedbc8501b4d938b8dfbda571ff0ecc1) |
| 4.x | 4.0.0 | 7.6.0–8.6.99 | [tag commit `d46b878`](https://github.com/lochmueller/staticfilecache/tree/d46b878d5929f205eb16414cc4eeb9bf6178e080) |
| 5.x | 5.0.0 | 8.7.0–8.7.99 | [tag commit `3f83c81`](https://github.com/lochmueller/staticfilecache/tree/3f83c812acf8f7c6ff6fb1da237ac3eca4e81f81) |
| 6.x | 6.0.0 | 8.7.0–9.3.99 | [tag commit `ea47384`](https://github.com/lochmueller/staticfilecache/tree/ea473841f81e653f57b13187360fbcbd6df6ab23) |
| 7.x | 7.0.0 | 8.7.0–9.5.99 | [tag commit `e4163a1`](https://github.com/lochmueller/staticfilecache/tree/e4163a15bff465391c02fac5768c6f9f3208682c) |
| 8.x | 8.0.0 | 8.7.0–9.5.99 | [tag commit `de659b3`](https://github.com/lochmueller/staticfilecache/tree/de659b344910c893bea2e0a4ec2cdfad2e56b3f2) |
| 9.x | 9.0.0 | 8.7.0–10.0.99 | [tag commit `a5f0028`](https://github.com/lochmueller/staticfilecache/tree/a5f0028eedd1e924916de9a0009ece214f3bbf7c) |
| 10.x | 10.0.0 | 9.5.0–10.9.99 | [tag commit `d97c108`](https://github.com/lochmueller/staticfilecache/tree/d97c108f6ed60f242afe0e2824b9ee0daf69f18f) |
| 11.x | 11.0.0 | 9.5.0–10.4.99 | [tag commit `c7b1cf2`](https://github.com/lochmueller/staticfilecache/tree/c7b1cf21fba2e00472d9e372f26e5043939c5cb7) |
| 12.x | 12.4.0 | 10.4.6–11.5.99 | [tag commit `f464980`](https://github.com/lochmueller/staticfilecache/tree/f4649802f1a11a86ace0215c5e6b8e6975583da6) |
| 13.x | 13.0.0 | 11.5.0–12.4.99 | [tag commit `c8d1421`](https://github.com/lochmueller/staticfilecache/tree/c8d14210ada3433e515f0f947c257c9d34c4d34a) |
| 14.x | 14.0.0 | 12.4.0–13.4.99 | [tag commit `73dba60`](https://github.com/lochmueller/staticfilecache/tree/73dba60817c7c6b51239a1b315626176a855e633) |
| 15.x | 15.0.0 | 12.4.0–13.4.99 | [tag commit `def6b7c`](https://github.com/lochmueller/staticfilecache/tree/def6b7cdfcc7ee9913a37c92c0385a578dccbc76) |
| 16.x | 16.2.0 (latest tag observed) | 14.0.0–14.4.99 | [tag commit `4c872c6`](https://github.com/lochmueller/staticfilecache/tree/4c872c60c8b85be84fe9f2ab97febd46494ac64d) |

**TYPO3 4.7 conclusion:** 2.5.2 is source-verified as compatible because its `ext_emconf.php` declares `TYPO3_version = 4.5.0-6.2.99`; TER version history also marks 2.5.2 (and 2.4.0, 2.4.1, 2.5.1) as supporting 4.5 LTS. The 3.6.0 generation drops that compatibility (minimum 6.2). Repository tags observed run 3.6.0–16.2.0; tag details do not establish exact historical layouts for every `nc_staticfilecache` 1.x/2.x release. The downloaded 2.5.2 ZIP SHA-256 is `8481b11958fadabd46ca7b3ef38102885df5cdc38d95e855da08c141787be20b`.

## Cache filename layouts and encoding

- **`nc_staticfilecache` 2.5.2:** default cache root `typo3temp/tx_nc_staticfilecache/`; runtime adds lowercase HTTP host, then request URI path. Example form: `typo3temp/tx_nc_staticfilecache/example.org/path/to/page/index.html`. The source first requires no query string, non-external page, and HTTP (it excludes HTTPS in the shown branch); path with no allowed file type gets `/index.html`. Source: [official 2.5.2 source ZIP](https://extensions.typo3.org/extension/download/nc_staticfilecache/2.5.2/zip), `class.tx_ncstaticfilecache.php`, `insertPageIncache()` and `$cacheDir`. Therefore do not carry modern scheme/port layout assumptions back to TYPO3 4.7.
- **3.6.0–7.x** (verified 3.6.0, 4.0.0, 5.0.0, 6.0.0, 7.0.0): `typo3temp/tx_staticfilecache/{scheme}/{host}/{trimmed-path}`; no port component in inspected algorithms. Unconfigured/non-eligible file-type paths append `index.html`. See [3.6.0 `StaticFileBackend.php`](https://github.com/lochmueller/staticfilecache/blob/69e68449dedbc8501b4d938b8dfbda571ff0ecc1/Classes/Cache/StaticFileBackend.php#L118-L128) and [6.0.0 commented port implementation](https://github.com/lochmueller/staticfilecache/blob/ea473841f81e653f57b13187360fbcbd6df6ab23/Classes/Cache/StaticFileBackend.php#L239-L256).
- **8.0–9.x** (verified 8.0.0, 8.2.0, 9.0.0): `typo3temp/tx_staticfilecache/{scheme}/{host}/{port}/{trimmed-path}`. Port defaults to 443 for HTTPS, otherwise 80; configured port is retained. Directory-style pages append `index.html`. [8.0.0 `StaticFileBackend.php`](https://github.com/lochmueller/staticfilecache/blob/de659b344910c893bea2e0a4ec2cdfad2e56b3f2/Classes/Cache/StaticFileBackend.php).
- **10.0+:** commit [`4584d374`](https://github.com/lochmueller/staticfilecache/commit/4584d3745d86bc8bb737fdd90cbc362c6807a9a7), first in 10.0.0, combines scheme/host/port as one component: `{scheme}_{host}_{port}`. Example: `.../typo3temp/tx_staticfilecache/https_example.org_443/foo/bar/index`. Filename uses trimmed URL path and terminal `index` (no `.html`). [10.0.0 `IdentifierBuilder.php`](https://github.com/lochmueller/staticfilecache/blob/d97c108f6ed60f242afe0e2824b9ee0daf69f18f/Classes/Cache/IdentifierBuilder.php).
- `rawurldecodeCacheFileName` was added in [`c2b7debc`](https://github.com/lochmueller/staticfilecache/commit/c2b7debc59f1a0cf65dcbe2e8356cabc410b5ab4). When enabled, `rawurldecode()` is applied to URL path before composing the file path; otherwise percent-encoded path remains as parsed. Verify actual configuration, do not deduce it from version. [16.2.0 `IdentifierBuilder.php`](https://github.com/lochmueller/staticfilecache/blob/4c872c60c8b85be84fe9f2ab97febd46494ac64d/Classes/Cache/IdentifierBuilder.php).
- Root changed in [`29e059a`](https://github.com/lochmueller/staticfilecache/commit/29e059abda3788bb9f98e94600136ab0da76a331), first in 13.0.0, from `typo3temp/tx_staticfilecache/` to `typo3temp/assets/tx_staticfilecache/`. Both support `overrideCacheDirectory`; actual config can differ. Current code resolves relative to TYPO3 public path. [16.2.0 `CacheService.php`](https://github.com/lochmueller/staticfilecache/blob/4c872c60c8b85be84fe9f2ab97febd46494ac64d/Classes/Service/CacheService.php).
- Query string is not part of modern `parse_url()` path-to-filename logic; stock Apache examples serve cache only with empty `QUERY_STRING`. Query-bearing routes must use an explicit standalone-exporter policy.
- **Hashed folders:** [`d1380df`](https://github.com/lochmueller/staticfilecache/commit/d1380df37fb3452bfb86f22d3e84e49a80a0fa3b) hashes long URL identifiers using SHA-256 as a **database cache key** (`hash('sha256', $entryIdentifier)`); filesystem page paths still use `IdentifierBuilder`. A separate cache backend hashes remote-file storage folders, not page snapshots. No inspected page-cache algorithm verifies a legacy hashed-folder mapping.

## Legacy TYPO3 public assets

The generated static asset rules account for TYPO3 4.7's old paths as well as current `Resources/Public` and `_assets` paths. Source: [TYPO3 4.7.20 inline2TempFile](https://github.com/TYPO3/typo3/blob/TYPO3_4-7-20/typo3/sysext/cms/tslib/class.tslib_pagegen.php#L1133-L1137) writes `typo3temp/javascript_<hash>.js` and `typo3temp/stylesheet_<hash>.css`; [TYPO3 4.7.20 compressor](https://github.com/TYPO3/typo3/blob/TYPO3_4-7-20/t3lib/class.t3lib_compressor.php#L39) uses `typo3temp/compressor/`. Existing assets are not copied or rewritten. Review additional extension-specific asset directories before switching.

## Apache and document-root findings

- Shipped `Documentation/Configuration/Htaccess.rst` sets `SFC_ROOT` to `%{DOCUMENT_ROOT}` and comments an explicit subdirectory installation override (`%{DOCUMENT_ROOT}/t3site`). The 11.0+ docs warn some Apache versions/configurations lack `DOCUMENT_ROOT`; configure `SFC_ROOT` explicitly. Resolve root relative to actual served filesystem root.
- Stock v9+ rules use scheme/host/port/URI, serve existing files only if query string is empty, request is GET, and SFC cookie is absent. Stock rules also contain a `.sfc` manifest rewrite to `index.php?eID=sfc_manifest`, plus direct-cache-path denial. **Do not copy manifest fallback** for a no-public-PHP exporter. Sources: [9.0.0 htaccess docs](https://github.com/lochmueller/staticfilecache/blob/a5f0028eedd1e924916de9a0009ece214f3bbf7c/Documentation/Configuration/Htaccess.rst), [16.2.0 docs](https://github.com/lochmueller/staticfilecache/blob/4c872c60c8b85be84fe9f2ab97febd46494ac64d/Documentation/Configuration/Htaccess.rst).
- Adapt cache root to specific generation/config; do not reproduce unrelated manifest/cache-management behavior.

## Source index

- [TER `nc_staticfilecache` release history](https://extensions.typo3.org/extension/nc_staticfilecache) and [2.5.2 downloadable source](https://extensions.typo3.org/extension/download/nc_staticfilecache/2.5.2/zip).
- [Maintained repository tags](https://github.com/lochmueller/staticfilecache/tags); version constraints are `ext_emconf.php` at immutable commits linked above.
- Path code: `Classes/Cache/StaticFileBackend.php`, `Classes/Cache/IdentifierBuilder.php`, `Classes/Service/CacheService.php` at cited commits.
- Apache rules: `Documentation/Configuration/Htaccess.rst` at cited commits.
