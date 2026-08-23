<?php
// #34264 — AOT mb_strimwidth must not SIGSEGV when start/width are runtime ints.
// php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_strimwidth)

$s = 'übercafe';
$i = 0;
$w = 4;
var_dump(mb_strimwidth($s, $i, $w, '..'));
var_dump(mb_strimwidth('über', 0, 3, '..'));
$f = 0;
$ww = 3;
var_dump(mb_strimwidth('über', $f, $ww, '..'));
