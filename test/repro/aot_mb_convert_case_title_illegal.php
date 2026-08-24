<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_case(MB_CASE_TITLE) on illegal UTF-8 must emit '?' not U+0080 (#34344).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
 */
$s = 'AbC'.\chr(0x80);
echo 'title80=', \bin2hex(\mb_convert_case($s, \MB_CASE_TITLE, 'UTF-8')), "\n";

$s2 = \chr(0x80).'Ab';
echo 'titleLead=', \bin2hex(\mb_convert_case($s2, \MB_CASE_TITLE, 'UTF-8')), "\n";

$s3 = 'A'.\chr(0x80).'b';
echo 'titleMid=', \bin2hex(\mb_convert_case($s3, \MB_CASE_TITLE, 'UTF-8')), "\n";

$s4 = 'ab'.\chr(0xC0).\chr(0x80);
echo 'titleOver=', \bin2hex(\mb_convert_case($s4, \MB_CASE_TITLE, 'UTF-8')), "\n";

$ok = 'hello world';
echo 'titleOk=', \mb_convert_case($ok, \MB_CASE_TITLE, 'UTF-8'), "\n";
