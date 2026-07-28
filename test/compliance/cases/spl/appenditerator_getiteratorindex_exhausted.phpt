--TEST--
AppendIterator::getIteratorIndex() null when exhausted (#24245, ext/spl/spl_iterators.c)
--FILE--
<?php
$a = new AppendIterator();
echo "empty=", var_export($a->getIteratorIndex(), true), " valid=", $a->valid() ? "1" : "0", "\n";
$a->append(new ArrayIterator([1, 2]));
$a->append(new ArrayIterator([3]));
$a->rewind();
echo "mid0=", var_export($a->getIteratorIndex(), true), "\n";
$a->next();
$a->next();
echo "mid1=", var_export($a->getIteratorIndex(), true), " valid=", $a->valid() ? "1" : "0", "\n";
foreach ($a as $v) {
}
echo "exhausted=", var_export($a->getIteratorIndex(), true), " valid=", $a->valid() ? "1" : "0", "\n";
?>
--EXPECT--
empty=NULL valid=0
mid0=0
mid1=1 valid=1
exhausted=NULL valid=0
