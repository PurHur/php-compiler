--TEST--
RecursiveArrayIterator::hasChildren/getChildren excess argc JIT (#31042, spl_array.c)
--FILE--
<?php
$it = new RecursiveArrayIterator([1, [2, 3]]);
$it->next();
try {
    var_export($it->hasChildren(1));
    echo "\n";
    echo "hasChildren COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'hasChildren ', $e->getMessage(), "\n";
}
try {
    echo get_class($it->getChildren(1)), "\n";
    echo "getChildren COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'getChildren ', $e->getMessage(), "\n";
}
echo 'has_ok=', $it->hasChildren() ? '1' : '0', "\n";
echo 'child=', get_class($it->getChildren()), "\n";
?>
--EXPECT--
hasChildren RecursiveArrayIterator::hasChildren() expects exactly 0 arguments, 1 given
getChildren RecursiveArrayIterator::getChildren() expects exactly 0 arguments, 1 given
has_ok=1
child=RecursiveArrayIterator
