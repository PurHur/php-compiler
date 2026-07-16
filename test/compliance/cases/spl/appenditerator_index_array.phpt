--TEST--
AppendIterator getIteratorIndex/getArrayIterator (#19481, ext/spl/spl_iterators.c)
--FILE--
<?php
$a = new AppendIterator();
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
$a->rewind();
$a->next();
$a->next();
echo $a->getIteratorIndex(), "\n";
echo $a->getArrayIterator()->count(), "\n";
$vals = [];
foreach ($a as $v) {
    $vals[] = $v;
}
echo implode(',', $vals), "\n";
?>
--EXPECT--
1
2
1,2,3
