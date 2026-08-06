<?php
/**
 * Issue #26976 — thin AOT array_udiff with arrow value comparator must match Zend.
 * Expected: 1,3
 */
$r = array_udiff([1, 2, 3], [2, 4], fn ($a, $b) => $a <=> $b);
echo implode(',', $r), "\n";
