<?php
$data = ['foo' => 1, 'bar' => 2];

// EXTR_PREFIX_SAME — prefix only when key already exists as variable
$pre_foo = 0;
extract($data, EXTR_PREFIX_SAME, 'pre_');
echo "same: ", $pre_foo, " ", ${'pre_foo'} ?? 'undef', "\n";

// EXTR_PREFIX_ALL — always prefix keys (php-src joins prefix + '_' + key)
extract($data, EXTR_PREFIX_ALL, 'all');
echo "all: ", $all_foo ?? 'undef', "\n";

// EXTR_IF_EXISTS — only import keys that already exist as variables
$bar = 99;
extract(['foo' => 1, 'bar' => 2, 'baz' => 3], EXTR_IF_EXISTS);
echo "if_exists bar=", $bar, " baz=", $baz ?? 'undef', "\n";
