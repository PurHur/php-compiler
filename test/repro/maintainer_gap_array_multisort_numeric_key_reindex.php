<?php

declare(strict_types=1);

// Repro for #13449 — array_multisort() reindexes numeric keys to 0..n-1 (php-src ext/standard/array.c).

$a = [3 => 30, 1 => 10, 2 => 20];
array_multisort($a);
if ([0, 1, 2] !== array_keys($a)) {
    echo 'fail: default sort keys ', var_export(array_keys($a), true), " — expected [0,1,2]\n";
    exit(1);
}

$b = [2 => 'c', 0 => 'a', 1 => 'b'];
array_multisort($b, SORT_STRING);
if ([0, 1, 2] !== array_keys($b)) {
    echo 'fail: SORT_STRING keys ', var_export(array_keys($b), true), " — expected [0,1,2]\n";
    exit(1);
}

$c = [2 => 30, 0 => 10, 1 => 20];
array_multisort($c, SORT_DESC);
if ([0, 1, 2] !== array_keys($c)) {
    echo 'fail: SORT_DESC keys ', var_export(array_keys($c), true), " — expected [0,1,2]\n";
    exit(1);
}

$d = [2 => 20, 1 => 10];
$e = [2 => 'b', 1 => 'a'];
array_multisort($d, $e);
if ([0, 1] !== array_keys($d) || [0, 1] !== array_keys($e)) {
    echo 'fail: two-array keys d=', var_export(array_keys($d), true), ' e=', var_export(array_keys($e), true), "\n";
    exit(1);
}

echo "ok\n";
