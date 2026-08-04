--TEST--
EmptyIterator::current throws BadMethodCallException under AOT (#27582)
--FILE--
<?php
$n = 0;
foreach (new EmptyIterator() as $v) {
    $n++;
}
echo "count=$n\n";
try {
    (new EmptyIterator())->current();
    echo "current_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
count=0
BadMethodCallException:Accessing the value of an EmptyIterator
