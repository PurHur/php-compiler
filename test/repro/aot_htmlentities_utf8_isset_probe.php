<?php
// #35067 / leftover #35050 — htmlentities UTF-8 must map under NestedJIT for string locals
// (literals fold at compile time and hide the bug).
$a = '<a>&b';
echo htmlentities($a, ENT_QUOTES, 'UTF-8'), "\n";
$b = 'é';
echo htmlentities($b, ENT_QUOTES, 'UTF-8'), "\n";
$c = 'café';
echo htmlentities($c, ENT_QUOTES, 'UTF-8'), "\n";
$d = '€';
echo htmlentities($d, ENT_QUOTES, 'UTF-8'), "\n";
$e = 'noop';
echo htmlentities($e, ENT_QUOTES, 'UTF-8'), "\n";
