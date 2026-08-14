<?php

/**
 * Repro #31001 — ArrayObject ArrayAccess methods reject excess argc.
 * php-src: ext/spl/spl_array.c ZEND_PARSE_PARAMETERS_ARGS
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

$o = new ArrayObject([1, 2]);
show('offsetExists', static fn () => $o->offsetExists(0, 1));
show('offsetGet', static fn () => $o->offsetGet(0, 1));
show('offsetSet', static fn () => $o->offsetSet(0, 9, 1));
show('offsetUnset', static fn () => $o->offsetUnset(1, 1));
show('offsetExists_ok', static fn () => $o->offsetExists(0));
show('offsetGet_ok', static fn () => $o->offsetGet(0));
show('offsetSet_ok', static function () use ($o) {
    $o->offsetSet(0, 9);

    return $o->offsetGet(0);
});
show('offsetUnset_ok', static function () use ($o) {
    $o->offsetUnset(1);

    return $o->offsetExists(1);
});
