<?php

/**
 * Issue #21981 — AOT smoke: nested array_keys(producer) slot wiring.
 * Uses array_values/array_unique (array_flip NestedJIT hits iteratekeyed — separate).
 */
echo implode(',', array_keys(array_values(['x' => 1, 'y' => 2]))), "\n";
echo implode(',', array_keys(array_unique(['a', 'a', 'b']))), "\n";
echo implode(',', array_keys(array_merge(['a' => 1], ['b' => 2]))), "\n";
