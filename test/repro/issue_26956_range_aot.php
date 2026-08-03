<?php

/**
 * Issue #26956 — thin AOT range(1,3) must print 1,2,3 (no segfault).
 * php-src: ext/standard/array.c — PHP_FUNCTION(range)
 */
echo implode(',', range(1, 3)), "\n";
