--TEST--
stdlib ArrayObject::getIteratorClass()/setIteratorClass() — default + validation (#10639, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject([1, 2, 3]);
var_export($ao->getIteratorClass());
echo "\n";
$ao->setIteratorClass('ArrayIterator');
var_export($ao->getIteratorClass());
echo "\n";
try {
    $ao->setIteratorClass('stdClass');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
'ArrayIterator'
'ArrayIterator'
TypeError: ArrayObject::setIteratorClass(): Argument #1 ($iteratorClass) must be a class name derived from ArrayIterator, stdClass given
