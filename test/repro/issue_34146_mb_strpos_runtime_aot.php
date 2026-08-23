<?php

/**
 * #34146 — mb_strpos() runtime (non-constant) args under thin AOT.
 * Leftover of #27187 (literal fold only).
 */
$h = '日本語';
$n = '本';
var_dump(mb_strpos($h, $n));
var_dump(mb_strpos($h, 'z'));
var_dump(mb_strpos('hello world', 'world'));
