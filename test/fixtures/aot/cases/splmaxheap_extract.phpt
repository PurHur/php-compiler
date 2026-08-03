--TEST--
AOT: SplMaxHeap::extract() order (#27276)
--FILE--
<?php
$h = new SplMaxHeap();
$h->insert(1);
$h->insert(5);
$h->insert(3);
echo $h->extract(), ',', $h->extract(), "\n";
echo $h->count(), "\n";
--EXPECT--
5,3
1
