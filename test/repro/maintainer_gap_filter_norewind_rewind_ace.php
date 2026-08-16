<?php
/** Maintainer gap: FilterIterator/NoRewindIterator::rewind excess argc silent — Zend ACE (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

class AcceptAllFilter extends FilterIterator
{
    public function accept(): bool
    {
        return true;
    }
}

echo "FilterIterator::rewind\n";
try {
    $f = new AcceptAllFilter(new ArrayIterator([1]));
    $f->rewind(1);
    echo "accepted\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}

echo "NoRewindIterator::rewind\n";
try {
    $n = new NoRewindIterator(new ArrayIterator([1]));
    $n->rewind(1);
    echo "accepted\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
