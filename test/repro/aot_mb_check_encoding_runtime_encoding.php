<?php

declare(strict_types=1);

/**
 * #35211 — mb_check_encoding() with runtime encoding under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_check_encoding)
 */
function enc(string $e): string
{
    return $e;
}

$ok = "café";
$bad = "\xff";
echo 'utf8=', var_export(mb_check_encoding($ok, enc('UTF-8')), true), "\n";
echo 'ascii=', var_export(mb_check_encoding('abc', enc('ASCII')), true), "\n";
$lit = 'UTF-8';
echo 'literal=', var_export(mb_check_encoding($ok, $lit), true), "\n";
echo 'bad_utf8=', var_export(mb_check_encoding($bad, enc('UTF-8')), true), "\n";
echo 'default=', var_export(mb_check_encoding($ok), true), "\n";
try {
    echo mb_check_encoding($ok, enc('nope'));
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
