<?php

declare(strict_types=1);

/**
 * Issue #9324: bool literal builtin args must bind to correct parameter slots.
 */
var_export(array_slice([0, 1, 2, 3], 1, 2, true));
echo "\n";
var_export(array_chunk([1, 2, 3], 2, true));
echo "\n";
var_export(in_array(1, [1, 2, 3], true));
echo "\n";

$pk = true;
var_export(array_slice([0, 1, 2, 3], 1, 2, $pk));
echo "\n";
