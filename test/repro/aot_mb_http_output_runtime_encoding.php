<?php

declare(strict_types=1);

/**
 * #35231 — mb_http_output() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_http_output)
 */
function enc(string $e): string
{
    return $e;
}

var_dump(mb_http_output(enc('ISO-8859-1')));
var_dump(mb_http_output());
var_dump(mb_http_output(enc('pass')));
var_dump(mb_http_output());
var_dump(mb_http_output('UTF-8'));
var_dump(mb_http_output());
try {
    mb_http_output(enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
var_dump(mb_http_output());
