<?php

/**
 * Repro #30998 — SplHeap corruption helpers + SplPriorityQueue extract-flag excess argc.
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

$h = new SplMaxHeap();
$h->insert(1);
show('h_isCorrupted', static fn () => $h->isCorrupted(1));
show('h_recoverFromCorruption', static fn () => $h->recoverFromCorruption(1));
show('h_isCorrupted_ok', static fn () => $h->isCorrupted());
show('h_recover_ok', static fn () => $h->recoverFromCorruption());

$q = new SplPriorityQueue();
$q->insert('a', 1);
show('q_setExtractFlags', static fn () => $q->setExtractFlags(SplPriorityQueue::EXTR_BOTH, 99));
show('q_getExtractFlags', static fn () => $q->getExtractFlags(1));
show('q_isCorrupted', static fn () => $q->isCorrupted(1));
show('q_recoverFromCorruption', static fn () => $q->recoverFromCorruption(1));
show('q_set_ok', static fn () => $q->setExtractFlags(SplPriorityQueue::EXTR_BOTH));
show('q_get_ok', static fn () => $q->getExtractFlags());
show('q_isCorrupted_ok', static fn () => $q->isCorrupted());
show('q_recover_ok', static fn () => $q->recoverFromCorruption());
