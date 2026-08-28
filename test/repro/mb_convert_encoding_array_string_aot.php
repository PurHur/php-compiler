<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_encoding() array $string — convert string elements, preserve others (#3222).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_convert_encoding)
 */
$lit = mb_convert_encoding(['a' => "café", 'n' => 7], 'ISO-8859-1', 'UTF-8');
echo 'lit=', bin2hex($lit['a']), '|', (string) $lit['n'], "\n";

$s = "é";
$rt = mb_convert_encoding(['k' => $s], 'ISO-8859-1', 'UTF-8');
echo 'rt=', bin2hex($rt['k']), "\n";
