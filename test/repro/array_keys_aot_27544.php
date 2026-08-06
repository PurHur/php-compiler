<?php
// #27544 — thin AOT array_keys (unfiltered + filtered) must print keys (not empty / not segfault).
$a = ['a' => 1, 'b' => 2, 'c' => 1];
echo implode(',', array_keys($a)), "\n";
echo implode(',', array_keys($a, 1)), "\n";
