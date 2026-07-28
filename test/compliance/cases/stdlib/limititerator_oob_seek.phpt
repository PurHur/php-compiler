--TEST--
LimitIterator rewind past SeekableIterator end — OutOfBoundsException (#24295, ext/spl/spl_iterators.c)
--FILE--
<?php
try {
    $it = new LimitIterator(new ArrayIterator([1, 2, 3]), 5, 1);
    $it->rewind();
    echo "no throw\n";
} catch (OutOfBoundsException $e) {
    echo $e->getMessage(), "\n";
}

try {
    $it = new LimitIterator(new ArrayIterator([1, 2, 3]), 3, 1);
    $it->rewind();
    echo "off3 no throw\n";
} catch (OutOfBoundsException $e) {
    echo 'off3:', $e->getMessage(), "\n";
}

$it = new LimitIterator(new ArrayIterator([1, 2, 3]), 2, 1);
$it->rewind();
echo 'off2:', $it->current(), "\n";
?>
--EXPECT--
Seek position 5 is out of range
off3:Seek position 3 is out of range
off2:3
