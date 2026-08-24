<?php

declare(strict_types=1);

/**
 * AOT: mb_strtoupper / mb_convert_case UPPER on illegal UTF-8 must match Zend (no SIGSEGV).
 *
 * NestedJIT MbCaseJitHelper must not call VmMbstring (#34340 leftover of #34280).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strtoupper|mb_convert_case)
 */
$s = 'AbC'.\chr(0x80);
echo 'up80=', \bin2hex(\mb_strtoupper($s, 'UTF-8')), "\n";
echo 'cc80=', \bin2hex(\mb_convert_case($s, \MB_CASE_UPPER, 'UTF-8')), "\n";

$s2 = 'a'.\chr(0xE9).'b';
echo 'upE9=', \bin2hex(\mb_strtoupper($s2, 'UTF-8')), "\n";
echo 'lowE9=', \bin2hex(\mb_strtolower($s2, 'UTF-8')), "\n";

$ok = 'café';
echo 'upOk=', \mb_strtoupper($ok, 'UTF-8'), "\n";
