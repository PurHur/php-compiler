<?php
/**
 * Repro #30909 — WeakMap offsetExists/offsetGet/offsetUnset excess argc
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
show('offsetExists', fn() => $wm->offsetExists($o, 1));
show('offsetGet', fn() => $wm->offsetGet($o, 1));
show('offsetUnset', function () use ($wm, $o) {
    $wm->offsetUnset($o, 1);
    return 'ok';
});
show('offsetExists0', fn() => $wm->offsetExists());
show('offsetGet0', fn() => $wm->offsetGet());
show('offsetUnset0', function () use ($wm) {
    $wm->offsetUnset();
    return 'ok';
});
show('offsetExists_ok', fn() => $wm->offsetExists($o));
show('offsetGet_ok', fn() => $wm->offsetGet($o));
show('offsetUnset_ok', function () use ($wm, $o) {
    $wm->offsetUnset($o);
    return isset($wm[$o]) ? 'y' : 'n';
});
