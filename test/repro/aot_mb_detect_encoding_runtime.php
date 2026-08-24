<?php

declare(strict_types=1);

/**
 * AOT: mb_detect_encoding() runtime haystack (#34358 leftover of #3075).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_encoding)
 */
$euro = \chr(0xE2).\chr(0x82).\chr(0xAC);
echo 'rt1=', \mb_detect_encoding($euro), "\n";

$hello = \chr(0x68).\chr(0x65).\chr(0x6C).\chr(0x6C).\chr(0x6F);
echo 'rtAscii=', \mb_detect_encoding($hello), "\n";

echo 'list=', \mb_detect_encoding($euro, ['UTF-8', 'ASCII'], true), "\n";
echo 'csv=', \mb_detect_encoding($euro, 'UTF-8,ASCII', true), "\n";
echo 'lit=', \mb_detect_encoding('hello'), "\n";
