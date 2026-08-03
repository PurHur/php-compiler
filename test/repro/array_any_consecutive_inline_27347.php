<?php

/**
 * Issue #27347 — consecutive expression-position array_any/array_all/array_find
 * with inline array literals + arrows (PROFILE=8.4).
 */
var_dump(array_any([1, 2, 3], fn($v) => $v > 5));
var_dump(array_any([1, 2, 3], fn($v) => $v > 1));
var_dump(array_all([1, 2, 3], fn($v) => $v > 0));
var_dump(array_find([1, 2, 3], fn($v) => $v === 2));
