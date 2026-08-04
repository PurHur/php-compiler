--TEST--
AOT iterator_to_array(null) / boxed null TypeError — no Generator resume (#27634)
--FILE--
<?php
try {
    $r = iterator_to_array(null);
    echo "NO_THROW_LIT:" . gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$x = null;
try {
    $r = iterator_to_array($x);
    echo "NO_THROW_VAR:" . gettype($r), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo implode(',', iterator_to_array(new ArrayIterator([1, 2]), false)), "\n";
--EXPECT--
TypeError:iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, null given
TypeError:iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, null given
1,2
