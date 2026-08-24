<?php
// Repro #34497: thin AOT var_export/print_r(array) must match Zend (no SIGABRT).
echo var_export([], true), "\n";
echo var_export([1, 2], true), "\n";
echo var_export(['a' => 1, 'b' => 2], true), "\n";
echo var_export([1, [2, 'x' => 3]], true), "\n";
echo print_r([], true);
echo print_r([1], true);
echo print_r(['k' => [1]], true);
