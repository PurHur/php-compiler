--TEST--
RecursiveArrayIterator::getChildren excess argc (#31042, spl_array.c)
--FILE--
<?php
$it = new RecursiveArrayIterator([1, [2, 3]]);
$it->next();
try {
    echo get_class($it->getChildren(1)), "\n";
    echo "getChildren COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'getChildren ', $e->getMessage(), "\n";
}
try {
    $it->getChildren();
    echo "getChildren_ok\n";
} catch (Throwable $e) {
    echo 'getChildren_zero ', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
getChildren RecursiveArrayIterator::getChildren() expects exactly 0 arguments, 1 given
getChildren_ok
