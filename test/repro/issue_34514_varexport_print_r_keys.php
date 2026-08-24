<?php
// Repro #34514: var_export then print_r must not mutate string keys.
$a = ['a' => 1, 'b' => 2];
echo var_export($a, true), "\n";
echo print_r($a, true);
