--TEST--
FilterIterator / NoRewindIterator excess argc (#31678)
--FILE--
<?php
class AcceptAllFilter extends FilterIterator
{
    public function accept(): bool
    {
        return true;
    }
}

$f = new AcceptAllFilter(new ArrayIterator([1]));
foreach (['rewind', 'next', 'valid', 'current', 'key', 'getInnerIterator'] as $m) {
    try {
        $f->$m(1);
        echo "Filter $m COERCED\n";
    } catch (ArgumentCountError $e) {
        echo 'Filter ', $m, ' ', $e->getMessage(), "\n";
    }
}

$n = new NoRewindIterator(new ArrayIterator([1]));
foreach (['rewind', 'next', 'valid', 'current', 'key', 'getInnerIterator'] as $m) {
    try {
        $n->$m(1);
        echo "NR $m COERCED\n";
    } catch (ArgumentCountError $e) {
        echo 'NR ', $m, ' ', $e->getMessage(), "\n";
    }
}

$f->rewind();
echo 'filter_valid_ok=', $f->valid() ? '1' : '0', "\n";
$n->rewind();
echo 'nr_valid_ok=', $n->valid() ? '1' : '0', "\n";
?>
--EXPECT--
Filter rewind FilterIterator::rewind() expects exactly 0 arguments, 1 given
Filter next FilterIterator::next() expects exactly 0 arguments, 1 given
Filter valid IteratorIterator::valid() expects exactly 0 arguments, 1 given
Filter current IteratorIterator::current() expects exactly 0 arguments, 1 given
Filter key IteratorIterator::key() expects exactly 0 arguments, 1 given
Filter getInnerIterator IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
NR rewind NoRewindIterator::rewind() expects exactly 0 arguments, 1 given
NR next NoRewindIterator::next() expects exactly 0 arguments, 1 given
NR valid NoRewindIterator::valid() expects exactly 0 arguments, 1 given
NR current NoRewindIterator::current() expects exactly 0 arguments, 1 given
NR key NoRewindIterator::key() expects exactly 0 arguments, 1 given
NR getInnerIterator IteratorIterator::getInnerIterator() expects exactly 0 arguments, 1 given
filter_valid_ok=1
nr_valid_ok=1
