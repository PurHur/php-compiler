<?php
/**
 * Repro #31129 — WeakMap::count() excess argc
 * (Zend/zend_weakrefs.c / zend_weakrefs.stub.php).
 */
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
