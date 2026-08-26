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

echo 'default=', var_export(mb_internal_encoding(), true), "\n";
echo 'set_iso=', var_export(mb_internal_encoding(enc('ISO-8859-1')), true), "\n";
echo 'get_iso=', var_export(mb_internal_encoding(), true), "\n";
$lit = 'UTF-8';
echo 'set_lit=', var_export(mb_internal_encoding($lit), true), "\n";
echo 'get_lit=', var_export(mb_internal_encoding(), true), "\n";
echo 'set_ascii=', var_export(mb_internal_encoding(enc('ASCII')), true), "\n";
echo 'get_ascii=', var_export(mb_internal_encoding(), true), "\n";
try {
    echo mb_internal_encoding(enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
echo 'after_bad=', var_export(mb_internal_encoding(), true), "\n";
