#!/usr/bin/env python3
"""Real Apache 2.4 regression tests. Requires Docker, php:8.3-cli and httpd:2.4."""
import http.client
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import time
import urllib.parse

PROJECT = pathlib.Path(__file__).resolve().parents[1]
APACHE_CONFIG = '\n'.join([
    'ServerRoot /usr/local/apache2', 'Listen 80', 'ServerName example.test',
    'LoadModule mpm_event_module modules/mod_mpm_event.so',
    'LoadModule unixd_module modules/mod_unixd.so',
    'LoadModule authz_core_module modules/mod_authz_core.so',
    'LoadModule mime_module modules/mod_mime.so',
    'LoadModule dir_module modules/mod_dir.so',
    'LoadModule rewrite_module modules/mod_rewrite.so',
    'LoadModule headers_module modules/mod_headers.so',
    'User daemon', 'Group daemon', 'PidFile /tmp/httpd.pid',
    'ErrorLog /proc/self/fd/2', 'LogLevel warn',
    'TypesConfig conf/mime.types', 'DocumentRoot /data/www',
    '<Directory /data/www>', 'AllowOverride All', 'Options FollowSymLinks',
    'Require all granted', '</Directory>',
])


def docker(*args, **kwargs):
    result = subprocess.run(['docker', *args], check=True, text=True, capture_output=True, **kwargs)
    return (result.stdout + (result.stderr if args[0] == 'logs' else '')).strip()


def php(code, *args):
    return docker('run', '--rm', '-v', f'{PROJECT}:/app:ro', '-w', '/app', 'php:8.3-cli', 'php', '-r', code, *args)


def request(port, path, method='GET', host='example.test', headers=None):
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    connection.request(method, path, headers={**(headers or {}), 'Host': host})
    response = connection.getresponse()
    result = response.status, response.read(), response.getheader('Content-Type', ''), response.getheader('X-Total-StaticFileCache')
    connection.close()
    return result


profiles = json.loads(php('require "src/Profiles.php"; echo json_encode(array_keys(TotalStaticFileCache\\profiles()));'))
checks = 0
for version in profiles:
    for mount in ['', '/site', '/space%20site', '/caf%C3%A9']:
        page_paths = ['/', '/news/', '/caf%C3%A9/', '/space%20here/', '/a&b/', '/html-as-feed.xml',
                      '/news/src/', '/news/vendor/', '/news/typo3_src/', '/news/config/',
                      '/news/var/', '/news/tests/', '/news/node_modules/']
        denied_pages = ['/vendor/', '/typo3_src/', '/config/', '/var/', '/src/', '/tests/', '/node_modules/',
                        '/news/shell.php/']
        urls = ['http://example.test' + mount + path for path in page_paths + denied_pages]
        secondary_paths = ['/', '/news/', '/secondary-only/']
        urls += ['http://second.test' + mount + path for path in secondary_paths]
        generated = json.loads(php(
            'require "src/Profiles.php"; require "src/Apache.php"; '
            '$p=TotalStaticFileCache\\profile($argv[1]); $p["pages"]=json_decode($argv[2],true); '
            '$files=[]; foreach($p["pages"] as $url){$files[$url]=TotalStaticFileCache\\cacheFile($p,$url);} '
            'echo json_encode(["rules"=>TotalStaticFileCache\\apacheRules($p,["http://example.test","http://second.test","https://empty.test"],$p["cacheDirectory"],$argv[3]),"files"=>$files,"dir"=>$p["cacheDirectory"]]);',
            version, json.dumps(urls), mount))
        with tempfile.TemporaryDirectory(prefix='fullstatic-apache-') as temp:
            root = pathlib.Path(temp)
            root.chmod(0o755)
            doc = root / 'www'
            site = doc / urllib.parse.unquote(mount).lstrip('/') if mount else doc
            site.mkdir(parents=True)
            (site / '.htaccess').write_text(generated['rules'])
            for url, relative in generated['files'].items():
                target = site / generated['dir'] / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('<html>dynamic plugin snapshot ' + url + '</html>')
            (site / generated['dir'] / '.htaccess').write_text('ForceType text/html\nAddDefaultCharset Off\nOptions -Indexes -MultiViews -ExecCGI\nHeader set X-Total-StaticFileCache "HIT" "expr=%{REQUEST_STATUS} == 200 || %{REQUEST_STATUS} == 304"\n')
            for relative, content in {
                'index.php': '<?php echo "PHP EXECUTED";',
                'shell.PHP': '<?php echo "PHP EXECUTED";',
                'fileadmin/image.svg': '<svg/>',
                'fileadmin/shell.php.jpg': '<?php echo "PHP EXECUTED";',
                'typo3conf/LocalConfiguration.php': 'SECRET',
                'typo3conf/ext/demo/Resources/Public/site.css': 'body{}',
                'typo3conf/ext/demo/Resources/Public/vendor/library.js': 'console.log(6)',
                'typo3conf/ext/demo/Resources/Public/src/library.js': 'console.log(7)',
                'vendor/private.js': 'SECRET',
                'src/private.js': 'SECRET',
                'config/private.json': 'SECRET',
                'var/private.txt': 'SECRET',
                'tests/private.txt': 'SECRET',
                'node_modules/private.js': 'SECRET',
                'typo3_src/private.js': 'SECRET',
                'fileadmin/.hidden/image.svg': 'SECRET',
                'typo3conf/ext/demo/Resources/Public/vendor/shell.php.js': 'SECRET',
                'typo3conf/ext/demo/res/legacy.css': 'body{}',
                'typo3temp/compressor/merged-abc.js': 'console.log(3)',
                'typo3temp/javascript_abcdef1234.js': 'console.log(4)',
                'typo3temp/stylesheet_abcdef1234.css': 'body{}',
                'typo3temp/pics/legacy.gif': 'GIF89a',
                'typo3/sysext/cms/tslib/media/scripts/gmenu_layers.js': 'console.log(5)',
                'typo3/sysext/demo/Resources/Public/core.js': 'console.log(2)',
                '_assets/abcd/site.js': 'console.log(1)',
                'robots.txt': 'User-agent: *',
                '.env': 'SECRET',
                'composer.json': 'SECRET',
            }.items():
                target = site / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(content)
            (root / 'httpd.conf').write_text(APACHE_CONFIG)
            container = docker('run', '-d', '--rm', '-p', '127.0.0.1::80', '-v', f'{root}:/data:ro',
                               'httpd:2.4', 'httpd-foreground', '-f', '/data/httpd.conf')
            try:
                port = int(docker('port', container, '80/tcp').rsplit(':', 1)[1])
                for attempt in range(50):
                    try:
                        request(port, mount + '/')
                        break
                    except (ConnectionError, OSError):
                        time.sleep(0.1)
                cases = [(mount + path, 'GET', 200, None) for path in page_paths]
                # Even an exact snapshot mapping must not bypass the private/script guards.
                cases += [(mount + path, 'GET', 404, None) for path in denied_pages]
                cases += [(mount + path, 'GET', status, None) for path, status in [
                    ('/missing/', 404), ('/index.php', 404), ('/shell.PHP', 404),
                    ('/index.php/news', 404), ('/typo3/', 404), ('/.env', 404),
                    ('/typo3conf/LocalConfiguration.php', 404), ('/composer.json', 404),
                    ('/fileadmin/shell.php.jpg', 404), ('/news/?x=1', 404), ('/news/?', 200),
                    ('/' + generated['dir'] + '/' + next(iter(generated['files'].values())), 404),
                    ('/fileadmin/image.svg', 200), ('/typo3conf/ext/demo/Resources/Public/site.css?v=1', 200),
                    ('/_assets/abcd/site.js', 200), ('/robots.txt', 200),
                    ('/typo3conf/ext/demo/res/legacy.css', 200), ('/typo3temp/compressor/merged-abc.js', 200),
                    ('/typo3temp/javascript_abcdef1234.js', 200), ('/typo3temp/stylesheet_abcdef1234.css', 200),
                    ('/typo3temp/pics/legacy.gif', 200), ('/typo3/sysext/cms/tslib/media/scripts/gmenu_layers.js', 200),
                    ('/typo3/sysext/demo/Resources/Public/core.js', 200),
                    ('/typo3conf/ext/demo/Resources/Public/vendor/library.js', 200),
                    ('/typo3conf/ext/demo/Resources/Public/src/library.js', 200),
                    ('/vendor/private.js', 404), ('/src/private.js', 404), ('/config/private.json', 404),
                    ('/var/private.txt', 404), ('/tests/private.txt', 404), ('/node_modules/private.js', 404),
                    ('/typo3_src/private.js', 404), ('/fileadmin/.hidden/image.svg', 404),
                    ('/typo3conf/ext/demo/Resources/Public/vendor/shell.php.js', 404),
                ]]
                cases += [(mount + '/news/', 'HEAD', 200, None), (mount + '/news/', 'POST', 405, None),
                          (mount + '/news/', 'GET', 404, 'wrong.test')]
                cases += [(mount + path, 'GET', 200, 'second.test') for path in secondary_paths]
                cases += [(mount + '/news/', 'GET', 200, 'SECOND.TEST:80'),
                          (mount + '/news/', 'HEAD', 200, 'second.test'),
                          (mount + '/news/?x=1', 'GET', 404, 'second.test'),
                          (mount + '/caf%C3%A9/', 'GET', 404, 'second.test'),
                          (mount + '/secondary-only/', 'GET', 404, 'example.test'),
                          (mount + '/index.php', 'GET', 404, 'second.test'),
                          (mount + '/fileadmin/shell.php.jpg', 'GET', 404, 'second.test'),
                          (mount + '/fileadmin/image.svg', 'GET', 200, 'second.test'),
                          (mount + '/news/', 'GET', 404, 'empty.test'),
                          (mount + '/fileadmin/image.svg', 'GET', 200, 'empty.test'),
                          (mount + '/news/', 'GET', 404, 'second.test.evil'),
                          (mount + '/news/', 'GET', 404, 'second.test:81')]
                for path, method, expected, host in cases:
                    status, body, content_type, cache_hit = request(port, path, method, host or 'example.test')
                    assert status == expected, (version, mount, path, method, status, expected, body,
                                                docker('logs', container))
                    assert b'PHP EXECUTED' not in body and b'SECRET' not in body, (path, body)
                    if expected == 200 and path in [mount + page for page in page_paths + secondary_paths] + [mount + '/news/?']:
                        assert content_type.startswith('text/html'), (path, content_type)
                        assert cache_hit == 'HIT', (path, cache_hit)
                        if method == 'GET':
                            authority = (host or 'example.test').lower().removesuffix(':80')
                            expected_body = '<html>dynamic plugin snapshot http://' + authority + path.removesuffix('?') + '</html>'
                            assert body == expected_body.encode(), (path, body)
                    else:
                        assert cache_hit is None, (path, status, cache_hit)
                    checks += 1
                for host in ['example.test', 'second.test']:
                    status, body, _, cache_hit = request(port, mount + '/', host=host,
                                                         headers={'If-None-Match': '*'})
                    assert (status, body, cache_hit) == (304, b'', 'HIT'), (host, status, body, cache_hit)
                    checks += 1
                missing_file = site / generated['dir'] / generated['files']['http://example.test' + mount + '/news/']
                missing_file.unlink()
                status, _, _, cache_hit = request(port, mount + '/news/')
                assert status == 404 and cache_hit is None, (status, cache_hit)
                checks += 1
                print(f'PASS Apache {version} mount={mount or "/"}')
            finally:
                docker('stop', '-t', '1', container)

# Serve the real v16 exporter output, including one failed domain and an all-failed release.
with tempfile.TemporaryDirectory(prefix='fullstatic-multisite-') as temp:
    root = pathlib.Path(temp)
    root.chmod(0o755)
    renderer = docker('run', '-d', '--rm', '-v', f'{PROJECT}:/app:ro',
                      '-e', 'EXPORTER_REQUEST_LOG=/tmp/requests.jsonl', 'php:8.3-cli',
                      'php', '-S', '0.0.0.0:8080', '/app/tests/fixtures/exporter-server.php')
    container = None
    try:
        docker('exec', renderer, 'php', '-r',
               'for($i=0;$i<50;$i++){if(@fsockopen("127.0.0.1",8080)){exit(0);}usleep(100000);}exit(1);')
        common = ['docker', 'run', '--rm', '--user', f'{os.getuid()}:{os.getgid()}',
                  '--network', 'container:' + renderer,
                  '-v', f'{PROJECT}:/app:ro', '-v', f'{root}:/release', '-w', '/app',
                  'php:8.3-cli', 'php', 'staticfilecache-v16.php',
                  '--base-url=http://fixture.test:8080/site',
                  '--resolve=fixture.test:8080:127.0.0.1', '--resolve=second.test:8080:127.0.0.1']
        exported = subprocess.run([*common, '--sitemap=http://fixture.test:8080/site/instance-pages.xml',
                                   '--sitemap=http://second.test:8080/site/instance-failure.xml',
                                   '--output=/release/export'], capture_output=True, text=True)
        assert exported.returncode == 1 and '1 page(s) failed' in exported.stderr, exported
        manifest = json.loads((root / 'export/manifest.json').read_text())
        assert manifest['complete'] is False and len(manifest['pages']) == 4, manifest
        assert manifest['origins'] == ['http://fixture.test:8080', 'http://second.test:8080'], manifest
        assert manifest['failures'][0]['url'] == 'http://second.test:8080/site/error', manifest
        checks += 4
        site = root / 'www/site'
        site.mkdir(parents=True)
        shutil.copytree(root / 'export' / manifest['cacheDirectory'], site / manifest['cacheDirectory'])
        shutil.copyfile(root / 'export/full-static.htaccess', site / '.htaccess')
        (site / 'index.php').write_text('<?php echo "PHP EXECUTED";')
        (site / 'fileadmin').mkdir()
        (site / 'fileadmin/image.svg').write_text('<svg/>')
        (root / 'httpd.conf').write_text(APACHE_CONFIG)
        container = docker('run', '-d', '--rm', '-p', '127.0.0.1::80', '-v', f'{root}:/data:ro',
                           'httpd:2.4', 'httpd-foreground', '-f', '/data/httpd.conf')
        port = int(docker('port', container, '80/tcp').rsplit(':', 1)[1])
        for attempt in range(50):
            try:
                request(port, '/site/shared/', host='fixture.test:8080')
                break
            except (ConnectionError, OSError):
                time.sleep(0.1)
        expected_pages = {page['url']: (root / 'export' / page['file']).read_bytes() for page in manifest['pages']}
        cases = [('fixture.test:8080', '/site/shared/', 200),
                 ('second.test:8080', '/site/shared/', 200),
                 ('fixture.test:8080', '/site/primary-only/', 200),
                 ('second.test:8080', '/site/primary-only/', 404),
                 ('second.test:8080', '/site/terminal/', 200),
                 ('fixture.test:8080', '/site/terminal/', 404),
                 ('fixture.test:8080', '/site/trusted-page-redirect', 404),
                 ('second.test:8080', '/site/error', 404),
                 ('second.test:8080', '/site/shared/?personal=1', 404),
                 ('second.test:8080', '/site/index.php', 404),
                 ('fixture.test:8080', '/site/fileadmin/image.svg?v=1', 200),
                 ('second.test:8080', '/site/fileadmin/image.svg?v=1', 200),
                 ('other.invalid:8080', '/site/shared/', 404),
                 ('second.test:8081', '/site/shared/', 404)]
        for host, path, expected in cases:
            status, body, content_type, cache_hit = request(port, path, host=host)
            assert status == expected, (host, path, status, expected, body, docker('logs', container))
            assert b'PHP EXECUTED' not in body, (path, body)
            if expected == 200 and 'fileadmin' not in path:
                assert body == expected_pages['http://' + host + path], (host, path, body)
                assert body.endswith(('<!-- http://' + host + ' -->').encode()), (host, body)
                assert b'rendered anonymously: 7' in body and content_type.startswith('text/html'), body
                assert cache_hit == 'HIT', (path, cache_hit)
            else:
                assert cache_hit is None, (path, status, cache_hit)
            checks += 1
        # Existing cache files must not become a fallback when every fresh capture failed.
        (root / 'errors.txt').write_text('/site/error\nhttp://second.test:8080/site/error\n')
        exported = subprocess.run([*common, '--urls=/release/errors.txt',
                                   '--sitemap=http://second.test:8080/site/empty.xml',
                                   '--output=/release/empty'], capture_output=True, text=True)
        assert exported.returncode == 1 and '2 page(s) failed' in exported.stderr, exported
        empty_manifest = json.loads((root / 'empty/manifest.json').read_text())
        assert empty_manifest['complete'] is False and empty_manifest['pages'] == [], empty_manifest
        checks += 2
        shutil.copyfile(root / 'empty/full-static.htaccess', site / '.htaccess')
        for host in ['fixture.test:8080', 'second.test:8080']:
            for path, expected in [('/site/shared/', 404), ('/site/error', 404),
                                   ('/site/index.php', 404), ('/site/fileadmin/image.svg', 200)]:
                status, body, _, cache_hit = request(port, path, host=host)
                assert status == expected and b'PHP EXECUTED' not in body, (host, path, status, body)
                assert cache_hit is None, (path, status, cache_hit)
                checks += 1
        print('PASS real v16 multisite incomplete and all-failed exports on Apache')
    finally:
        if container is not None:
            docker('stop', '-t', '1', container)
        docker('stop', '-t', '1', renderer)
print(f'PASS {checks} real Apache checks')
