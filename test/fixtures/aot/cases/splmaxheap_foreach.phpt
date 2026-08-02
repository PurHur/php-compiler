--TEST--
AOT: SplMaxHeap insert + foreach extract order (#26784)
--FILE--
<?php
$h = new SplMaxHeap();
$h->insert(3);
$h->insert(1);
$h->insert(2);
$out = [];
foreach ($h as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
echo count($h), "\n";
--EXPECT--
3,2,1
0
