--TEST--
ArrayObject iteratorClass getIteratorClass/getIterator (#27567)
--FILE--
<?php
class MyIter extends ArrayIterator {}
$a = new ArrayObject([1, 2], 0, MyIter::class);
echo 'gic=', $a->getIteratorClass(), "\n";
$it = $a->getIterator();
echo 'cls=', get_class($it), "\n";
foreach ($it as $k => $v) {
    echo "$k=$v ";
}
echo "\n";
--EXPECT--
gic=MyIter
cls=MyIter
0=1 1=2 
