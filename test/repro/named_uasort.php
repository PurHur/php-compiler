<?php
// Issue #10048 — usort()/uasort()/uksort() array:/callback: named parameters
$a = [3, 1, 2];
usort(array: $a, callback: fn ($x, $y) => $x <=> $y);
var_export($a);
