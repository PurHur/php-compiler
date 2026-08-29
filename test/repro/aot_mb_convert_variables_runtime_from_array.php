<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_variables() with runtime-built array $from_encoding (#35315 leftover).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_variables)
 */
function pick(string $s): string
{
    return $s;
}

$encs = [];
$encs[] = pick('UTF-8');
$encs[] = 'ISO-8859-1';

$a = "\xE9";
$r = mb_convert_variables('UTF-8', $encs, $a);
echo "r=$r a=$a\n";

$dyn = pick('ISO-8859-1');
$encs2 = [];
$encs2[] = $dyn;
$encs2[] = 'UTF-8';
$b = 'café';
$r2 = mb_convert_variables('UTF-8', $encs2, $b);
echo "r2=$r2 b=$b\n";
