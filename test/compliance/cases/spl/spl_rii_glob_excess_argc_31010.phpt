--TEST--
RecursiveIteratorIterator / GlobIterator residual excess argc (#31010)
--FILE--
<?php
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(sys_get_temp_dir()));
foreach ([
    'rewind', 'next', 'key', 'current', 'valid', 'getMaxDepth',
    'beginIteration', 'endIteration', 'callHasChildren', 'callGetChildren',
] as $m) {
    try {
        $rii->$m(1);
        echo "$m COERCED\n";
    } catch (ArgumentCountError $e) {
        echo $m, ' ', $e->getMessage(), "\n";
    }
}
$gi = new GlobIterator(__DIR__.'/*.php');
try {
    $gi->count(1);
    echo "count COERCED\n";
} catch (ArgumentCountError $e) {
    echo 'count ', $e->getMessage(), "\n";
}
$rii->rewind();
echo 'valid_ok=', $rii->valid() ? '1' : '0', "\n";
echo 'getMaxDepth_ok=', (false === $rii->getMaxDepth() || is_int($rii->getMaxDepth())) ? '1' : '0', "\n";
echo 'count_ok=', is_int($gi->count()) ? '1' : '0', "\n";
?>
--EXPECT--
rewind RecursiveIteratorIterator::rewind() expects exactly 0 arguments, 1 given
next RecursiveIteratorIterator::next() expects exactly 0 arguments, 1 given
key RecursiveIteratorIterator::key() expects exactly 0 arguments, 1 given
current RecursiveIteratorIterator::current() expects exactly 0 arguments, 1 given
valid RecursiveIteratorIterator::valid() expects exactly 0 arguments, 1 given
getMaxDepth RecursiveIteratorIterator::getMaxDepth() expects exactly 0 arguments, 1 given
beginIteration RecursiveIteratorIterator::beginIteration() expects exactly 0 arguments, 1 given
endIteration RecursiveIteratorIterator::endIteration() expects exactly 0 arguments, 1 given
callHasChildren RecursiveIteratorIterator::callHasChildren() expects exactly 0 arguments, 1 given
callGetChildren RecursiveIteratorIterator::callGetChildren() expects exactly 0 arguments, 1 given
count GlobIterator::count() expects exactly 0 arguments, 1 given
valid_ok=1
getMaxDepth_ok=1
count_ok=1
