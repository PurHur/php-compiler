<?php

declare(strict_types=1);

/**
 * #35187 — mb_str_pad() with runtime encoding under thin AOT (PROFILE=8.4+).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)
 *
 * UTF-8 pads by character width; ASCII/8BIT/BINARY by byte length.
 */
function enc(string $e): string
{
    return $e;
}

foreach (['UTF-8', 'ASCII', '8BIT', 'utf8', 'binary'] as $name) {
    $e = enc($name);
    $s = mb_str_pad('あ', 4, '.', STR_PAD_RIGHT, $e);
    echo $name, ' ', var_export($s, true), ' ', bin2hex($s), "\n";
}

$lit = 'UTF-8';
echo 'literal ', var_export(mb_str_pad('あ', 4, '.', STR_PAD_RIGHT, $lit), true), "\n";

try {
    $bad = enc('nope');
    echo mb_str_pad('x', 2, '.', STR_PAD_RIGHT, $bad);
    echo "no error\n";
} catch (ValueError $e) {
    echo 'bad_enc=', $e->getMessage(), "\n";
}
