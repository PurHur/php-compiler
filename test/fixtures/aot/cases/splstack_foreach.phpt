--TEST--
AOT: SplStack foreach LIFO via __spl_ht (#28705)
--FILE--
<?php
$s = new SplStack();
$s->push(1);
$s->push(2);
$s->push(3);
foreach ($s as $k => $v) {
    echo $k, ':', $v, ',';
}
echo "\n";
$q = new SplQueue();
$q->enqueue(10);
$q->enqueue(20);
foreach ($q as $v) {
    echo $v, ',';
}
echo "\n";
--EXPECT--
2:3,1:2,0:1,
10,20,
