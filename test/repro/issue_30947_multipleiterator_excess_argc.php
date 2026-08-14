<?php

/**
 * Repro #30947 — MultipleIterator excess argc → Zend ArgumentCountError.
 */
$m = new MultipleIterator();
$a = new ArrayIterator([1]);
foreach ([
    'attach' => fn () => $m->attachIterator($a, null, 'x'),
    'detach' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);
        $m2->detachIterator($a, 'x');
    },
    'contains' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);

        return $m2->containsIterator($a, 'x');
    },
    'getFlags' => function () use ($a) {
        $m2 = new MultipleIterator();
        $m2->attachIterator($a);

        return $m2->getFlags('x');
    },
] as $n => $fn) {
    try {
        $fn();
        echo "$n=acc\n";
    } catch (Throwable $e) {
        echo $n.'='.get_class($e).': '.$e->getMessage()."\n";
    }
}
