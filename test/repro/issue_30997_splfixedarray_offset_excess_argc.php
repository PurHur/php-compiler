<?php

/**
 * Repro #30997 — SplFixedArray ArrayAccess excess argc after #30836.
 * php-src: ext/spl/spl_fixedarray.c ZEND_PARSE_PARAMETERS_ARGS
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

$f = SplFixedArray::fromArray([10, 20, 30]);
show('offsetGet', static fn () => $f->offsetGet(0, 1));
show('offsetSet', static fn () => $f->offsetSet(0, 11, 1));
show('offsetExists', static fn () => $f->offsetExists(0, 1));
show('offsetUnset', static fn () => $f->offsetUnset(1, 1));

show('offsetGet_ok', static fn () => $f->offsetGet(0));
show('offsetExists_ok', static fn () => $f->offsetExists(0));
show('offsetSet_ok', static function () use ($f) {
    $f->offsetSet(0, 11);
});
show('offsetUnset_ok', static function () use ($f) {
    $f->offsetUnset(2);
});
echo 'after=', $f->offsetGet(0), ',', $f->offsetExists(2) ? '1' : '0', "\n";
