<?php
// Repro #23886 — RecursiveArrayIterator accepts ArrayObject.
$rao = new ArrayObject([1, [2, 3], 4]);
$rit = new RecursiveArrayIterator($rao);
$vals = [];
foreach (new RecursiveIteratorIterator($rit) as $v) {
    $vals[] = $v;
}
echo 'ok count=' . count($vals) . ' values=[' . implode(',', $vals) . "]\n";
