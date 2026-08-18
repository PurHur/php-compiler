<?php

declare(strict_types=1);

/**
 * parse_url('file:///') empty host is valid (php-src url.c, #32085).
 */
error_reporting(E_ALL);

foreach ([
    'file:///tmp/x',
    'file:///',
    'file://localhost/tmp/x',
    'file://',
    'http:///tmp/x',
] as $url) {
    echo $url, ' => ';
    var_export(parse_url($url));
    echo "\n";
}

echo 'PHP_URL_PATH=', var_export(parse_url('file:///tmp/x', PHP_URL_PATH), true), "\n";
echo 'PHP_URL_HOST=', var_export(parse_url('file:///tmp/x', PHP_URL_HOST), true), "\n";
echo 'PHP_URL_SCHEME=', var_export(parse_url('file:///', PHP_URL_SCHEME), true), "\n";
echo 'drive=', var_export(parse_url('file:///c:/somedir/file.txt'), true), "\n";
