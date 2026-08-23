<?php

/**
 * AOT repro: mb_str_split() with runtime length (#34278 leftover of #26870 / peer #34256).
 */
$n = 1;
echo implode(',', mb_str_split('abc', $n)), "\n";
echo implode(',', mb_str_split('abc', 1)), "\n";
$s = 'über';
$n2 = 2;
echo implode(',', mb_str_split($s, $n2)), "\n";
$n3 = 2;
echo implode(',', mb_str_split('abcdef', $n3)), "\n";
