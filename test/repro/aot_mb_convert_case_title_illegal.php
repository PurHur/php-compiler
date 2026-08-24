<?php

declare(strict_types=1);

/**
 * AOT: mb_convert_case(TITLE) on illegal UTF-8 must match Zend (`?`, not U+0080).
 *
 * NestedJIT MbConvertCaseJitHelper leftover of #34340 / #34344.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
 */
$s = 'AbC'.\chr(0x80);
echo 'title80=', \bin2hex(\mb_convert_case($s, \MB_CASE_TITLE, 'UTF-8')), "\n";

$s2 = \chr(0x80).'Ab';
echo 'titleLead=', \bin2hex(\mb_convert_case($s2, \MB_CASE_TITLE, 'UTF-8')), "\n";

$ok = 'hello world';
echo 'titleOk=', \mb_convert_case($ok, \MB_CASE_TITLE, 'UTF-8'), "\n";
