<?php

declare(strict_types=1);

// Issue #7293 — empty and all-unset arrays return null (php-src ext/standard/array.c).
var_dump(array_first([]));
var_dump(array_last([]));

$allUnset = [0 => 1];
unset($allUnset[0]);
var_dump(array_first($allUnset));
var_dump(array_last($allUnset));
