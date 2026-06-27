<?php

declare(strict_types=1);

/**
 * Repro #12721 — array_filter() inline closure must not wire Closure into $array slot.
 */

var_export(array_filter([1, 2, 3], fn (int $v): bool => $v > 1));
echo "\n";
