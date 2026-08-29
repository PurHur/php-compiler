<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_encoding() with runtime-built array $from_encoding (#35296 leftover).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 */
function pick(string $s): string
{
    return $s;
}

$s = "\xE9";
$encs = [];
$encs[] = pick('UTF-8');
$encs[] = 'ISO-8859-1';
echo var_export(mb_convert_encoding($s, 'UTF-8', $encs)), "\n";

$dyn = pick('ISO-8859-1');
echo var_export(mb_convert_encoding($s, 'UTF-8', [$dyn, 'UTF-8'])), "\n";
