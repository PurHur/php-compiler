<?php
// Spot check with #27276 — MinHeap extract / nextFreeElement sync after unset.
$h = new SplMinHeap();
$h->insert(5);
$h->insert(1);
$h->insert(3);
echo $h->extract(), ',', $h->extract(), ',', $h->extract(), "\n";
