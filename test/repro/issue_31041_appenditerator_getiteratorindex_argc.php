<?php
// Repro: AppendIterator::getIteratorIndex() excess argc (#31041)
$a = new AppendIterator();
$a->append(new ArrayIterator([1]));
$a->rewind();
try {
    var_export($a->getIteratorIndex(1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', var_export($a->getIteratorIndex(), true), "\n";
