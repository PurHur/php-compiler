<?php

declare(strict_types=1);

/**
 * mb_ord() runtime (non-literal) args under AOT / NestedJIT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_ord) — php_mb_ord decodes first char only;
 * trailing bytes after a valid ASCII lead still yield ord('A') (65), not false.
 */
$a = 'A'.'';
$jp = '日'.'';
$trail = 'A'."\xFF";
$bad = "\xC0\x80";
$euro = "\xE2\x82\xAC".'';

echo 'a=', var_export(mb_ord($a), true), "\n";
echo 'jp=', var_export(mb_ord($jp), true), "\n";
echo 'trail=', var_export(mb_ord($trail), true), "\n";
echo 'bad=', var_export(mb_ord($bad), true), "\n";
echo 'euro=', var_export(mb_ord($euro), true), "\n";
echo 'lit=', var_export(mb_ord('A'), true), "\n";
