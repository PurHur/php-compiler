<?php

/**
 * Repro #30965 — ArrayObject flags / iterator-class / getArrayCopy / user-sort excess argc.
 * php-src: ext/spl/spl_array.c ZEND_PARSE_PARAMETERS_*
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
$cmp = static fn ($x, $y) => $x <=> $y;
show('getFlags', static fn () => $o->getFlags(1));
show('setFlags', static fn () => $o->setFlags(0, 1));
show('getIteratorClass', static fn () => $o->getIteratorClass(1));
show('setIteratorClass', static fn () => $o->setIteratorClass('ArrayIterator', 1));
show('getArrayCopy', static fn () => $o->getArrayCopy(1));
show('uasort', static fn () => $o->uasort($cmp, 1));
show('uksort', static fn () => $o->uksort($cmp, 1));
show('getFlags_ok', static fn () => $o->getFlags());
show('setFlags_ok', static function () use ($o) {
    $o->setFlags(0);

    return $o->getFlags();
});
show('getIteratorClass_ok', static fn () => $o->getIteratorClass());
show('setIteratorClass_ok', static function () use ($o) {
    $o->setIteratorClass('ArrayIterator');

    return $o->getIteratorClass();
});
show('getArrayCopy_ok', static fn () => $o->getArrayCopy());
show('uasort_ok', static fn () => $o->uasort($cmp));
show('uksort_ok', static fn () => $o->uksort($cmp));
