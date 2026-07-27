<?php
// Issue #23985 / #23988 — array == and <=> compare key bags; === stays order-sensitive
// (Zend/zend_operators.c zend_compare_arrays → zend_hash_compare ordered=false).
var_export([0 => 1, 1 => 2] == [1 => 2, 0 => 1]);
echo "\n";
var_export(['a' => 1, 'b' => 2] == ['b' => 2, 'a' => 1]);
echo "\n";
var_export([0 => 1, 1 => 2] === [1 => 2, 0 => 1]);
echo "\n";
var_export([0 => 1, 1 => 2] <=> [1 => 2, 0 => 1]);
echo "\n";
var_export(['a' => 1, 'b' => 2] <=> ['b' => 2, 'a' => 1]);
echo "\n";
var_export([0 => 1] <=> [0 => 1, 1 => 2]);
echo "\n";
var_export([0 => 1, 1 => 2] <=> [0 => 1]);
echo "\n";
