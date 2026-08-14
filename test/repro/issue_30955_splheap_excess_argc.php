<?php

/**
 * Repro #30955 — SplHeap / SplPriorityQueue excess argc.
 * php-src: ext/spl/spl_heap.c ZEND_PARSE_PARAMETERS_*
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

$h = new SplMinHeap();
$h->insert(1);
foreach (['top', 'extract', 'isEmpty', 'count', 'valid', 'key', 'current', 'rewind', 'next'] as $m) {
    show($m, static fn () => $h->$m(1));
}
show('top_ok', static fn () => $h->top());
show('count_ok', static fn () => $h->count());
show('empty_ok', static fn () => $h->isEmpty());

$q = new SplPriorityQueue();
$q->insert('a', 1);
show('pq_top', static fn () => $q->top(1));
show('pq_cmp', static fn () => $q->compare(1, 2, 3));
show('pq_top_ok', static fn () => $q->top());
show('pq_cmp_ok', static fn () => $q->compare(1, 2));
