<?php
// Leftover of #35317 — boxed numeric-string ordered compares vs int
$a = '10';
var_dump($a < 10);
var_dump($a > 9);
var_dump($a >= 10);
var_dump($a <= 9);
$b = 10;
var_dump($a < $b);
var_dump($a > $b);
var_dump($a <= $b);
var_dump($a >= $b);
