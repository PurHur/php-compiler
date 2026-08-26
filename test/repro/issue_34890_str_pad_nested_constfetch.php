<?php

declare(strict_types=1);

/**
 * #34890 — str_pad() with STR_PAD_* ConstFetch + nested FuncCall (php 8.0+).
 * php-src: ext/standard/string.c PHP_FUNCTION(str_pad)
 */
function s(): string
{
    return '-';
}

echo 'str=', var_export(str_pad('a', 4, s(), STR_PAD_RIGHT), true), "\n";
echo 'str_left=', var_export(str_pad('a', 4, s(), STR_PAD_LEFT), true), "\n";
echo 'str_both=', var_export(str_pad('a', 4, s(), STR_PAD_BOTH), true), "\n";
echo 'str_lit=', var_export(str_pad('a', 4, s(), 1), true), "\n";
