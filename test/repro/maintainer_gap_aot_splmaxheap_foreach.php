<?php
// Repro for #26784 — AOT SplMaxHeap foreach (ObjectPropertyForeachHelper crash).
$h = new SplMaxHeap();
$h->insert(3);
$h->insert(1);
$h->insert(2);
$out = [];
foreach ($h as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
