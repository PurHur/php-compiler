<?php

declare(strict_types=1);

/**
 * #35280 — mb_detect_order() with runtime CSV setter under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_detect_order)
 */
function enc(): string
{
    return 'UTF-8,ASCII';
}

var_export(mb_detect_order(enc()));
echo "\n";
var_export(mb_detect_order());
echo "\n";
mb_detect_order(enc());
var_export(mb_detect_order());
echo "\n";
