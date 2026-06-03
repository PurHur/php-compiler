<?php
/**
 * Maintainer repro for #4967 — array internal pointer builtins.
 */
$a = ['a' => 1, 'b' => 2];
var_dump(key($a), current($a));
next($a);
var_dump(key($a), current($a));
reset($a);
var_dump(key($a));
