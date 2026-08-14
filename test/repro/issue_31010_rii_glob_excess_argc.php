<?php
/**
 * RecursiveIteratorIterator hooks + GlobIterator::count excess argc (#31010).
 * php-src: ext/spl/spl_iterators.c / spl_directory.c
 */
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(sys_get_temp_dir()));
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
foreach ([
    'rewind', 'next', 'key', 'current', 'valid', 'getMaxDepth',
    'beginIteration', 'endIteration', 'callHasChildren', 'callGetChildren',
] as $m) {
    show($m, static fn () => $rii->$m(1));
}
$gi = new GlobIterator(__DIR__.'/*.php');
show('count', static fn () => $gi->count(1));
$rii->rewind();
show('rewind_ok', static fn () => $rii->rewind());
show('valid_ok', static fn () => $rii->valid());
show('getMaxDepth_ok', static fn () => $rii->getMaxDepth());
show('count_ok', static fn () => $gi->count());
