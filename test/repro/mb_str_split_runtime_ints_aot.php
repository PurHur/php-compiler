<?php

/**
 * AOT repro: mb_str_split() with runtime split length (#34278 leftover of #26870 / peer #34256).
 *
 * Literal length and runtime subject alone must keep working; runtime length was SIGSEGV.
 */
$n = 1;
echo implode(',', mb_str_split('abc', 1)), "\n";
echo implode(',', mb_str_split('abc', $n)), "\n";
$s = 'abc';
echo implode(',', mb_str_split($s, 1)), "\n";
echo implode(',', mb_str_split($s, $n)), "\n";
$u = 'über';
$two = 2;
echo implode(',', mb_str_split($u, $two)), "\n";
