<?php
// Repro #22007 — ParentIterator under RII nested new + SELF_FIRST
$n = 0;
foreach (
    new RecursiveIteratorIterator(
        new ParentIterator(new RecursiveArrayIterator([1, [2, 3], 4])),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $k => $v
) {
    echo 'k=', $k, ' arr=', is_array($v) ? '1' : '0', "\n";
    $n++;
}
echo 'count=', $n, "\n";
