<?php

declare(strict_types=1);

/**
 * #35221 — mb_internal_encoding() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_internal_encoding)
 */
function enc(string $e): string
{
    return $e;
}

var_dump(mb_internal_encoding(enc('ISO-8859-1')));
var_dump(mb_internal_encoding());
var_dump(mb_internal_encoding('UTF-8'));
var_dump(mb_internal_encoding());
try {
    mb_internal_encoding(enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
var_dump(mb_internal_encoding());
