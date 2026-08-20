--TEST--
AOT: ArrayIterator count/append match Zend (#32910, ext/spl/spl_array.c)
--FILE--
<?php
$a = new ArrayIterator([1, 2, 3]);
echo 'm=', $a->count(), "\n";
echo 'f=', count($a), "\n";
$a->append(4);
echo 'after=', $a->count(), '|', implode(',', iterator_to_array($a)), "\n";
$a[5] = 9;
echo 'off=', $a->offsetExists(5) ? '1' : '0', ',', $a->offsetGet(5), ',', $a->count(), "\n";
--EXPECT--
m=3
f=3
after=4|1,2,3,4
off=1,9,5
