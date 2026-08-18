<?php

declare(strict_types=1);

/**
 * parse_url() digit-leading scheme + leading colon (php-src url.c, #32086).
 */
error_reporting(E_ALL);

foreach ([
    ':',
    ':80',
    '0://host',
    '1http://example.com',
    'http://example.com',
    'mailto:user@example.com',
    'file://localhost/tmp/x',
    '://host',
] as $url) {
    echo $url, ' => ';
    var_export(parse_url($url));
    echo "\n";
}

echo 'PHP_URL_SCHEME=', var_export(parse_url('0://host', PHP_URL_SCHEME), true), "\n";
echo 'PHP_URL_HOST=', var_export(parse_url('1http://example.com', PHP_URL_HOST), true), "\n";
echo 'PHP_URL_PATH=', var_export(parse_url(':', PHP_URL_PATH), true), "\n";
