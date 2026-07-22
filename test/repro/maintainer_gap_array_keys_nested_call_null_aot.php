<?php

/**
 * Issue #21981 — AOT smoke: nested array_keys(array_flip(...)) producer wiring.
 */
echo implode(',', array_keys(array_flip(['a', 'b']))), "\n";
echo implode(',', array_keys(array_values(['x' => 1, 'y' => 2]))), "\n";
echo implode(',', array_keys(array_unique(['a', 'a', 'b']))), "\n";
