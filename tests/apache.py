#!/usr/bin/env python3
"""Real Apache 2.4 regression tests. Requires Docker, php:8.3-cli and httpd:2.4."""
import http.client
import json
import pathlib
import subprocess
import tempfile
import time

PROJECT = pathlib.Path(__file__).resolve().parents[1]


def docker(*args, **kwargs):
    result = subprocess.run(['docker', *args], check=True, text=True, capture_output=True, **kwargs)
    return (result.stdout + (result.stderr if args[0] == 'logs' else '')).strip()


def php(code, *args):
    return docker('run', '--rm', '-v', f'{PROJECT}:/app:ro', '-w', '/app', 'php:8.3-cli', 'php', '-r', code, *args)


def request(port, path, method='GET', host='example.test'):
    connection = http.client.HTTPConnection('127.0.0.1', port, timeout=5)
    connection.request(method, path, headers={'Host': host})
    response = connection.getresponse()
    result = response.status, response.read(), response.getheader('Content-Type', '')
    connection.close()
    return result


profiles = json.loads(php('require "src/Profiles.php"; echo json_encode(array_keys(TotalStaticFileCache\\profiles()));'))
checks = 0
for version in profiles:
    for mount in ['', '/site']:
        urls = ['http://example.test' + mount + path for path in ['/', '/news/', '/caf%C3%A9/', '/space%20here/', '/a&b/', '/html-as-feed.xml']]
        generated = json.loads(php(
            'require "src/Profiles.php"; require "src/Apache.php"; '
            '$p=TotalStaticFileCache\\profile($argv[1]); $p["pages"]=json_decode($argv[2],true); '
            '$files=[]; foreach($p["pages"] as $url){$files[$url]=TotalStaticFileCache\\cacheFile($p,$url);} '
            'echo json_encode(["rules"=>TotalStaticFileCache\\apacheRules($p,"http://example.test",$p["cacheDirectory"],$argv[3]),"files"=>$files,"dir"=>$p["cacheDirectory"]]);',
            version, json.dumps(urls), mount))
        with tempfile.TemporaryDirectory(prefix='fullstatic-apache-') as temp:
            root = pathlib.Path(temp)
            root.chmod(0o755)
            doc = root / 'www'
            site = doc / mount.lstrip('/') if mount else doc
            site.mkdir(parents=True)
            (site / '.htaccess').write_text(generated['rules'])
            for url, relative in generated['files'].items():
                target = site / generated['dir'] / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text('<html>dynamic plugin snapshot ' + url + '</html>')
            (site / generated['dir'] / '.htaccess').write_text('ForceType text/html\nAddDefaultCharset Off\nOptions -Indexes -MultiViews -ExecCGI\n')
            for relative, content in {
                'index.php': '<?php echo "PHP EXECUTED";',
                'shell.PHP': '<?php echo "PHP EXECUTED";',
                'fileadmin/image.svg': '<svg/>',
                'fileadmin/shell.php.jpg': '<?php echo "PHP EXECUTED";',
                'typo3conf/LocalConfiguration.php': 'SECRET',
                'typo3conf/ext/demo/Resources/Public/site.css': 'body{}',
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
            config = '\n'.join([
                'ServerRoot /usr/local/apache2', 'Listen 80', 'ServerName example.test',
                'LoadModule mpm_event_module modules/mod_mpm_event.so',
                'LoadModule unixd_module modules/mod_unixd.so',
                'LoadModule authz_core_module modules/mod_authz_core.so',
                'LoadModule mime_module modules/mod_mime.so',
                'LoadModule dir_module modules/mod_dir.so',
                'LoadModule rewrite_module modules/mod_rewrite.so',
                'User daemon', 'Group daemon', 'PidFile /tmp/httpd.pid',
                'ErrorLog /proc/self/fd/2', 'LogLevel warn',
                'TypesConfig conf/mime.types', 'DocumentRoot /data/www',
                '<Directory /data/www>', 'AllowOverride All', 'Options FollowSymLinks',
                'Require all granted', '</Directory>',
            ])
            (root / 'httpd.conf').write_text(config)
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
                cases = [(mount + path, 'GET', 200, None) for path in ['/', '/news/', '/caf%C3%A9/', '/space%20here/', '/a&b/', '/html-as-feed.xml']]
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
                ]]
                cases += [(mount + '/news/', 'HEAD', 200, None), (mount + '/news/', 'POST', 405, None),
                          (mount + '/news/', 'GET', 404, 'wrong.test')]
                for path, method, expected, host in cases:
                    status, body, content_type = request(port, path, method, host or 'example.test')
                    assert status == expected, (version, mount, path, method, status, expected, body,
                                                docker('logs', container))
                    assert b'PHP EXECUTED' not in body and b'SECRET' not in body, (path, body)
                    if expected == 200 and path in [mount + '/', mount + '/news/', mount + '/news/?', mount + '/html-as-feed.xml']:
                        assert content_type.startswith('text/html'), (path, content_type)
                        if method == 'GET':
                            assert b'dynamic plugin snapshot' in body, (path, body)
                    checks += 1
                print(f'PASS Apache {version} mount={mount or "/"}')
            finally:
                docker('stop', '-t', '1', container)
print(f'PASS {checks} real Apache checks')
