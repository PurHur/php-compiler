<?php
/**
 * #34519 — thin AOT var_dump/print_r must not SIGSEGV on ints outside stream handle range.
 *
 * #34507 closed-stream probe GEPed phpc_stream_was_used[handle] before the range check.
 */
var_dump(42);
var_dump(1000000000);
var_dump(PHP_INT_MAX);
var_dump(PHP_INT_MIN);
echo print_r(1000000000, true), "\n";
$f = fopen('php://memory', 'r');
var_dump($f);
fclose($f);
var_dump($f);
