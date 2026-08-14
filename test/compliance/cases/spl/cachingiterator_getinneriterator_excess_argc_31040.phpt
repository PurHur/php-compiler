--TEST--
CachingIterator getInnerIterator rejects extra args (#31040)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator([1]));
try {
    echo get_class($it->getInnerIterator(1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', get_class($it->getInnerIterator()) === 'ArrayIterator' ? '1' : '0', "\n";
?>
--EXPECT--
ArgumentCountError: IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
ok=1
