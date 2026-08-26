<?php
// #35032 — AOT str_pad NestedJIT isset-length dropped padding (php-src string.c str_pad)
echo str_pad('p', 5, 'x'), "\n";
echo str_pad('p', 5, '-', STR_PAD_LEFT), "\n";
echo str_pad('p', 5, '-', STR_PAD_BOTH), "\n";
$x = 3;
echo str_pad('p', $x + 2, '-'), "\n";
$n = 3;
echo str_pad('hello world', $n + 13, '.'), "\n";
