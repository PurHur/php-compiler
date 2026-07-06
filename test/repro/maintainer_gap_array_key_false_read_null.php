<?php
declare(strict_types=1);
// Issue #16738 / #5275 — false array key read on string-keyed array must yield NULL.
$b = ['a' => 1];
var_export($b[false]);
echo "\n";
