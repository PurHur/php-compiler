--TEST--
ArrayObject clone deep-copies storage (#19803, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayObject([1, 2]);
$b = clone $a;
echo $b->count(), "\n";
$b[] = 3;
echo $a->count(), ":", $b->count(), "\n";

$i = new ArrayIterator(['x' => 1, 'y' => 2]);
$j = clone $i;
echo $j->count(), "\n";
$j['z'] = 3;
echo $i->count(), ":", $j->count(), "\n";
?>
--EXPECT--
2
2:3
2
2:3
