<?php
declare(strict_types=1);

// #34391 — AOT mb_split() with runtime strings (leftover #13367 / php_mbregex.c).
$p = ',';
$s = 'a,b,c';
echo implode('|', mb_split($p, $s)), "\n";
$pat = ' ';
$hay = 'x y z';
$lim = 2;
echo implode('|', mb_split($pat, $hay, $lim)), "\n";
$empty = '';
$subj = 'alone';
echo implode('|', mb_split($empty, $subj)), "\n";
$dash = '-';
$nums = '1-2-3-4';
echo implode('|', mb_split($dash, $nums, -1)), "\n";
$p2 = 'ab';
$s2 = 'xabyabz';
echo implode('|', mb_split($p2, $s2)), "\n";
