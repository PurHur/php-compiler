<?php

/**
 * #4637 — mb_substr_count() runtime (non-constant) args under thin AOT.
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_substr_count)
 */
$h = 'ababab';
$n = 'ab';
var_dump(mb_substr_count($h, $n));
$h2 = '日本語日本';
$n2 = '日本';
var_dump(mb_substr_count($h2, $n2));
var_dump(mb_substr_count('abab', 'ab'));
