--TEST--
SPL LimitIterator::seek() negative offset — OutOfBoundsException (#13963, ext/spl/spl_iterators.c)
--FILE--
<?php
$li = new LimitIterator(new ArrayIterator([1, 2, 3]), 0, 2);
try {
    $li->seek(-1);
    echo "uncaught\n";
} catch (OutOfBoundsException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
OutOfBoundsException: Cannot seek to -1 which is below the offset 0
