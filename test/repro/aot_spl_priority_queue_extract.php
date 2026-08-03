<?php
// Repro #27277 — AOT SplPriorityQueue::extract() by priority (default EXTR_DATA).
$q = new SplPriorityQueue();
$q->insert('a', 1);
$q->insert('b', 10);
$q->insert('c', 5);
echo $q->extract(), ',', $q->extract(), "\n";
