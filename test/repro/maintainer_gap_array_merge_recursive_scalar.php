<?php

declare(strict_types=1);

$r1 = array_merge_recursive(['a' => 1], ['a' => 2]);
$r2 = array_merge_recursive(['a' => ['x' => 1]], ['a' => ['y' => 2]]);
$r3 = array_merge_recursive(['a' => 1], ['a' => [2]]);
echo var_export($r1, true), "\n";
echo var_export($r2, true), "\n";
echo var_export($r3, true), "\n";
if ($r1 !== ['a' => [0 => 1, 1 => 2]]
    || $r2 !== ['a' => ['x' => 1, 'y' => 2]]
    || $r3 !== ['a' => [0 => 1, 1 => 2]]) {
    exit(1);
}
