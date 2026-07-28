<?php
// Differential: array literal dim-assign + array_shift left-to-right (#23979, #24055).
// Print without json_encode(): AOT NestedJIT json_encode of dynamics still segfaults
// (__object__load_value_slot in JsonEncodeJitHelper) — separate from this packing bug.
$a = [1, 2, 3];
$tmp = [$a[0] = 99, array_shift($a), $a];
echo '[', $tmp[0], ',', $tmp[1], ',[', $tmp[2][0], ',', $tmp[2][1], ']]', "\n";
