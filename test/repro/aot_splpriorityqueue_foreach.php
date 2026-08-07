<?php
// Repro #28708 — AOT SplPriorityQueue foreach yields priority order (default EXTR_DATA).
$q = new SplPriorityQueue();
$q->insert('a', 1);
$q->insert('b', 3);
$q->insert('c', 2);
foreach ($q as $v) {
    echo $v, ',';
}
echo "\n";
echo count($q), "\n";
