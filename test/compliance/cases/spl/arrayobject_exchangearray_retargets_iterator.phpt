--TEST--
ArrayObject::exchangeArray() retargets outstanding ArrayIterator (#24243, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject([1, 2, 3]);
$it = $ao->getIterator();
$it->rewind();
$before = $it->current();
$ao->exchangeArray([9, 8]);
$after = $it->current();
echo "before={$before} after={$after} copy=", implode(',', $ao->getArrayCopy()), "\n";

$ao2 = new ArrayObject([1, 2, 3]);
$it2 = $ao2->getIterator();
$it2->rewind();
$it2->next();
$ao2->exchangeArray([9, 8, 7]);
echo 'mid=', $it2->current(), ' key=', $it2->key(), ' valid=', (int) $it2->valid(), "\n";

$ao3 = new ArrayObject([1, 2, 3]);
$it3 = new ArrayIterator($ao3);
$it3->rewind();
$ao3->exchangeArray([40, 50]);
echo 'ctor=', $it3->current(), "\n";
?>
--EXPECT--
before=1 after=9 copy=9,8
mid=8 key=1 valid=1
ctor=40
