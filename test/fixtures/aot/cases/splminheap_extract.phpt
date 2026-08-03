--TEST--
AOT: SplMinHeap::extract() order + nextFree sync (#27276)
--FILE--
<?php
$h = new SplMinHeap();
$h->insert(5);
$h->insert(1);
$h->insert(3);
echo $h->extract(), ',', $h->extract(), ',', $h->extract(), "\n";
echo $h->count(), "\n";
--EXPECT--
1,3,5
0
