<?php
declare(strict_types=1);

// #34391 — AOT mb_split() with runtime strings (leftover #13367).
$p = ',';
$s = 'a,b,c';
echo implode('|', mb_split($p, $s)), "\n";
$empty = '';
$hay = 'x';
echo implode('|', mb_split($empty, $hay)), "\n";
$lim = 2;
echo implode('|', mb_split($p, $s, $lim)), "\n";
