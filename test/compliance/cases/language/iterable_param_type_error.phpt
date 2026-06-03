--TEST--
iterable parameter — TypeError mentions Traversable|array (#4829)
--FILE--
<?php
function f(iterable $i) {
    return count($i);
}
echo f([1, 2]), "\n";
try {
    f(1);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
2
TypeError: Argument must be of type Traversable|array, int given
