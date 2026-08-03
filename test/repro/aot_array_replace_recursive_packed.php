<?php

// Repro for #26977 — packed list + nested string overlay
$a = array_replace_recursive([1, 2, 3], [0 => 10, 2 => ['z' => 9]]);
$inner = $a[2];
echo $a[0], ',', $a[1], ',', $inner['z'], PHP_EOL;
