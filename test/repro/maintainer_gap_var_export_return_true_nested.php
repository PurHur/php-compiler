<?php

declare(strict_types=1);

/**
 * Maintainer repro: var_export($nested_builtin, true) returns NULL (#10373).
 */

echo var_export(substr('hello', 0, -2), true), "\n";
echo var_export(explode(',', 'a,b', -1), true), "\n";
echo var_export(array_keys(['a' => 1, 'b' => 2]), true), "\n";

$x = substr('hello', 0, -2);
echo var_export($x, true), "\n";

ob_start();
var_export(substr('hello', 0, -2));
echo ob_get_clean(), "\n";
