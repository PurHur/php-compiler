--TEST--
RecursiveIteratorIterator::getSubIterator OOB/negative returns null (#24315, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2, 3], 4]));
$it->rewind();
while ($it->valid()) {
    if (2 === $it->current()) {
        break;
    }
    $it->next();
}
echo 'oob=', var_export($it->getSubIterator(99), true), "\n";
echo 'neg=', var_export($it->getSubIterator(-1), true), "\n";
echo 'past=', var_export($it->getSubIterator(2), true), "\n";
echo 'l0=', get_class($it->getSubIterator(0)), "\n";
echo 'l1=', get_class($it->getSubIterator(1)), "\n";
?>
--EXPECT--
oob=NULL
neg=NULL
past=NULL
l0=RecursiveArrayIterator
l1=RecursiveArrayIterator
