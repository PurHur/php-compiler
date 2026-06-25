<?php

declare(strict_types=1);

/**
 * Maintainer repro: getallheaders() must not exist in plain CLI (issue #11780).
 *
 * php-src: ext/standard/head.c — PHP_MINIT registers getallheaders only for
 * apache/apache2handler/cli-server, not generic cli.
 */

if (function_exists('getallheaders')) {
    echo "fail function_exists=true\n";
    exit(1);
}

if (function_exists('apache_request_headers')) {
    echo "fail apache_request_headers registered\n";
    exit(1);
}

echo "ok\n";
