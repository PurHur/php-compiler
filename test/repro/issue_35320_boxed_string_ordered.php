<?php
// #35320 leftover of #35317 — boxed numeric-string ordered compare vs int
$a = "10";
$b = 10;
var_dump($a < 10);
var_dump($a > 9);
var_dump($a >= 10);
var_dump($a <= 10);
var_dump($a > $b);
var_dump($a >= $b);
var_dump($a < $b);
var_dump($a <= $b);
$c = "10.0";
var_dump($c < 10);
var_dump($c >= 10);
