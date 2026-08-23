<?php

/**
 * AOT repro: mb_str_pad() with runtime pad length (#34270 leftover of #6081 / peer #34264).
 *
 * Requires PHP_COMPILER_PROFILE=8.3+ (php-src 8.3+ mb_str_pad).
 */
$n = 10;
var_dump(mb_str_pad('ü', $n, '-'));
var_dump(mb_str_pad('ü', 10, '-'));
$left = 8;
var_dump(mb_str_pad('x', $left, '.', 0));
