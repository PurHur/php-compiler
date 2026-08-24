<?php
declare(strict_types=1);

// #34391 — AOT mb_split() with runtime strings (leftover of #13367).
$p = ',';
$s = 'a,b,c';
echo implode('|', mb_split($p, $s)), PHP_EOL;
$dash = '-';
$hi = 'hi';
echo implode('|', mb_split($dash, $hi)), PHP_EOL;
