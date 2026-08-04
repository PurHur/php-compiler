--TEST--
iterator_count ArrayIterator / array / null TypeError (#27633)
--FILE--
<?php
echo iterator_count(new ArrayIterator([1, 2, 3])), "\n";
try {
    echo iterator_count(null);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo iterator_count([1, 2, 3]), "\n";
--EXPECT--
3
TypeError:iterator_count(): Argument #1 ($iterator) must be of type Traversable|array, null given
3
