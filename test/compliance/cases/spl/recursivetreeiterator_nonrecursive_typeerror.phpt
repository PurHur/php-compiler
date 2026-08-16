--TEST--
RecursiveTreeIterator non-RecursiveIterator inner — TypeError cites RecursiveCachingIterator (#31596, ext/spl/spl_iterators.c)
--FILE--
<?php
try {
    new RecursiveTreeIterator(new ArrayIterator([1]));
    echo "no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (InvalidArgumentException $e) {
    echo 'InvalidArgumentException:', $e->getMessage(), "\n";
}
class AggOk implements IteratorAggregate {
    public function getIterator(): Traversable {
        return new RecursiveArrayIterator([1]);
    }
}
$ok = new RecursiveTreeIterator(new AggOk());
echo $ok instanceof RecursiveTreeIterator ? "agg-ok\n" : "agg-bad\n";
$rai = new RecursiveTreeIterator(new RecursiveArrayIterator([1]));
echo $rai instanceof RecursiveTreeIterator ? "rai-ok\n" : "rai-bad\n";
?>
--EXPECT--
RecursiveCachingIterator::__construct(): Argument #1 ($iterator) must be of type RecursiveIterator, ArrayIterator given
agg-ok
rai-ok
