--TEST--
AOT packed sort still reorders list values after #25385 VM reindex fix
--FILE--
<?php
$a = ['c', 'a', 'b'];
sort($a);
echo $a[0], $a[1], $a[2], "\n";
$a = [3, 1, 2];
rsort($a);
echo $a[0], ',', $a[1], ',', $a[2], "\n";
--EXPECT--
abc
3,2,1
