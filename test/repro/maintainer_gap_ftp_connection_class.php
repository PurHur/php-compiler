<?php

declare(strict_types=1);

/**
 * Maintainer gap repro: FTP\Connection class on PHP 8.4 forward profile (#7270).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_ftp_connection_class.php
 */
if (!class_exists('FTP\\Connection', false)) {
    echo "fail: FTP\\Connection missing\n";
    exit(1);
}

$ref = new ReflectionClass('FTP\\Connection');
if ('FTP\\Connection' !== $ref->getName()) {
    echo 'fail: unexpected name '.$ref->getName()."\n";
    exit(1);
}

echo "ok\n";
