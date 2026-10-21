#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "The external exporter requires PHP 8.2+; TYPO3 may use its own older PHP runtime.\n");
    exit(1);
}
require __DIR__ . '/src/Profiles.php';
require __DIR__ . '/src/Apache.php';
require __DIR__ . '/src/Exporter.php';

exit(\TotalStaticFileCache\run(\TotalStaticFileCache\profile('staticfilecache-v6'), $argv));
