--TEST--
AppendIterator getIteratorIndex rejects extra args (#31041)
--FILE--
<?php
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
?>
--EXPECT--
ArgumentCountError: AppendIterator::getIteratorIndex() expects exactly 0 arguments, 1 given
ok=0
