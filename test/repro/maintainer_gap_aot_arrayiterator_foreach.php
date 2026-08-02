<?php
// Repro for #26783 — AOT ArrayIterator foreach (ObjectPropertyForeachHelper crash).
$it = new ArrayIterator([1, 2, 3]);
$out = [];
foreach ($it as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
