<?php
/**
 * Maintainer repro for #10349 — key() after unset on current element.
 */
declare(strict_types=1);

$a = ['x' => 1, 'y' => 2];
unset($a['x']);
var_export(key($a));
echo "\n";
