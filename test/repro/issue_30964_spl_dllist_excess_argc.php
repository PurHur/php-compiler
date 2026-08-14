<?php

/**
 * Repro #30964 — SplDoublyLinkedList / SplQueue residual excess argc after #30911/#30952.
 * php-src: ext/spl/spl_dllist.c ZEND_PARSE_PARAMETERS_*
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$l = new SplDoublyLinkedList();
$l->push(1);
$l->push(2);
show('push', static fn () => $l->push(3, 1));
show('unshift', static fn () => $l->unshift(0, 1));
show('isEmpty', static fn () => $l->isEmpty(1));
show('bottom', static fn () => $l->bottom(1));
show('add', static fn () => $l->add(1, 9, 1));
show('setIteratorMode', static fn () => $l->setIteratorMode(0, 1));
show('getIteratorMode', static fn () => $l->getIteratorMode(1));
show('offsetExists', static fn () => $l->offsetExists(0, 1));
show('offsetGet', static fn () => $l->offsetGet(0, 1));
show('offsetSet', static fn () => $l->offsetSet(0, 9, 1));
show('offsetUnset', static fn () => $l->offsetUnset(0, 1));

$q = new SplQueue();
$q->enqueue(1);
show('enqueue', static fn () => $q->enqueue(2, 1));
show('dequeue', static fn () => $q->dequeue(1));

show('push_ok', static function () use ($l) {
    $l->push(3);
});
show('isEmpty_ok', static fn () => $l->isEmpty());
show('bottom_ok', static fn () => $l->bottom());
show('enqueue_ok', static function () use ($q) {
    $q->enqueue(2);
});
