<?php
// #24167 — variadic pack must be usable as array (array_sum), not only implode-tolerant.
// AOT previously printed "Object" via NestedJIT Variable return; fixed via ArraySumLlvm.
function sv(...$v) { echo array_sum($v), "\n"; }
$p = [1, 2, 3];
sv(...$p);
