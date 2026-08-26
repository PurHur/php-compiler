<?php

declare(strict_types=1);

/**
 * #35199 — mb_trim()/mb_ltrim()/mb_rtrim() with runtime encoding under thin AOT (PROFILE=8.4+).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_trim)
 *
 * 8BIT/BINARY leave whitespace; UTF-8/ASCII trim default charset.
 */
function enc(string $e): string
{
    return $e;
}

foreach (['UTF-8', 'ASCII', '8BIT', 'utf8', 'binary'] as $name) {
    $e = enc($name);
    $s = mb_trim(" a ", null, $e);
    echo 'trim ', $name, ' ', var_export($s, true), "\n";
    $s = mb_ltrim(" a ", null, $e);
    echo 'ltrim ', $name, ' ', var_export($s, true), "\n";
    $s = mb_rtrim(" a ", null, $e);
    echo 'rtrim ', $name, ' ', var_export($s, true), "\n";
}

$lit = 'UTF-8';
echo 'literal ', var_export(mb_trim(" a ", null, $lit), true), "\n";

try {
    $bad = enc('nope');
    echo mb_trim(" a ", null, $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
