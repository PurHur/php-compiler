--TEST--
SPL EmptyIterator::current()/key() — BadMethodCallException (#24246, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new EmptyIterator();
try {
    $it->current();
    echo "current:no-exception\n";
} catch (BadMethodCallException $e) {
    echo 'current:', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $it->key();
    echo "key:no-exception\n";
} catch (BadMethodCallException $e) {
    echo 'key:', get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
current:BadMethodCallException: Accessing the value of an EmptyIterator
key:BadMethodCallException: Accessing the key of an EmptyIterator
