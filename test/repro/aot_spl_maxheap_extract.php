<?php
// Repro #27276 — AOT SplMaxHeap::extract() must match Zend order (not silent null).
$h = new SplMaxHeap();
$h->insert(1);
$h->insert(5);
$h->insert(3);
echo $h->extract(), ',', $h->extract(), "\n";
