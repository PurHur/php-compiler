--TEST--
language: WeakMap::count() excess argc → ArgumentCountError JIT (#31129, Zend/zend_weakrefs.c)
--FILE--
<?php
function show(string $label, callable $fn): void
{
    try {
        $r = $fn();
        echo $label, ': ', var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 1;
show('count_excess', fn() => $wm->count(1));
show('count_ok', fn() => $wm->count());
show('count_fn', fn() => count($wm));
--EXPECT--
count_excess: ArgumentCountError: WeakMap::count() expects exactly 0 arguments, 1 given
count_ok: 1
count_fn: 1
