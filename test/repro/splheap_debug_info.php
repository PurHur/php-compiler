<?php
// Repro #19825 — SplHeap/SplPriorityQueue var_dump must show flags+heap.
$h = new SplMinHeap();
$h->insert(3);
$h->insert(1);
var_dump($h);
$p = new SplPriorityQueue();
$p->insert('a', 1);
$p->insert('b', 2);
var_dump($p);
