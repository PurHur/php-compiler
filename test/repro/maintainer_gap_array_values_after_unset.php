<?php

declare(strict_types=1);

/**
 * Repro #12723 — array_values() after unset() must omit removed elements.
 */

$a = ['a' => 1, 'b' => 2, 'c' => 3];
unset($a['b']);
var_export(array_values($a));
echo "\n";
