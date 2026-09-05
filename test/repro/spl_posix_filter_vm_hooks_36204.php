<?php
// Filter constant resolve + SplMaxHeap via Module hooks (#36204).
echo defined('FILTER_VALIDATE_EMAIL') ? 'filter-ok' : 'filter-miss';
echo ' ';
$h = new SplMaxHeap();
$h->insert(1);
$h->insert(3);
$h->insert(2);
echo $h->extract(), ',', $h->extract(), ',', $h->extract(), "\n";
