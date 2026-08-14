<?php

/**
 * Repro #30956 — RecursiveIteratorIterator / ParentIterator excess argc.
 * php-src: ext/spl/spl_iterators.c ZEND_PARSE_PARAMETERS_*
 */
function show(string $label, callable $fn): void
{
    try {
        $v = $fn();
        echo $label, ': OK ', var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$it = new RecursiveIteratorIterator(new RecursiveArrayIterator([1, [2]]));
foreach ($it as $v) {
    show('depth', static fn () => $it->getDepth(1));
    show('max', static fn () => $it->setMaxDepth(1, 2));
    show('sub', static fn () => $it->getSubIterator(0, 1));
    show('depth_ok', static fn () => $it->getDepth());
    show('max_ok', static fn () => $it->setMaxDepth(1));
    show('sub_ok', static fn () => get_class($it->getSubIterator()));
    break;
}
$p = new ParentIterator(new RecursiveArrayIterator([[1], [2]]));
$p->rewind();
show('accept', static fn () => $p->accept(1));
show('has', static fn () => $p->hasChildren(1));
show('accept_ok', static fn () => $p->accept());
show('has_ok', static fn () => $p->hasChildren());
