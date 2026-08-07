--TEST--
AOT: SplPriorityQueue insert + foreach extract order (#28708)
--FILE--
<?php
$q = new SplPriorityQueue();
$q->insert('a', 1);
$q->insert('b', 3);
$q->insert('c', 2);
$out = [];
foreach ($q as $v) {
    $out[] = $v;
}
echo implode(',', $out), "\n";
echo count($q), "\n";
--EXPECT--
b,c,a
0
