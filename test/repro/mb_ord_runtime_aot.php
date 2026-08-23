<?php

/**
 * #34243 — mb_ord() runtime (non-constant) args under thin AOT.
 * Leftover of #33547 (literal fold / argc TypeError only).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_ord)
 */
$s = 'A';
var_dump(mb_ord($s));
$u = '日';
var_dump(mb_ord($u));
var_dump(mb_ord('A'));
$bad = "\x80";
var_dump(mb_ord($bad));
