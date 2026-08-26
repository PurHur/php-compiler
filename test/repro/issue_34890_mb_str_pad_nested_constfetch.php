<?php

declare(strict_types=1);

/**
 * #34890 — mb_str_pad() with STR_PAD_* + nested FuncCall (requires PHP_COMPILER_PROFILE=8.3+).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_str_pad)
 */
function s(): string
{
    return '-';
}

function enc(): string
{
    return 'UTF-8';
}

echo 'mb=', var_export(mb_str_pad('a', 4, s(), STR_PAD_RIGHT), true), "\n";
echo 'mb_enc=', var_export(mb_str_pad('a', 4, '-', STR_PAD_RIGHT, enc()), true), "\n";
echo 'mb_both=', var_export(mb_str_pad('a', 4, s(), 1, enc()), true), "\n";
echo 'mb_lit=', var_export(mb_str_pad('a', 4, s(), 1), true), "\n";
