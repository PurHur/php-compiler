--TEST--
language: WeakMap offsetExists/offsetGet/offsetUnset excess argc → ArgumentCountError JIT (#30909, Zend/zend_weakrefs.c)
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
--EXPECT--
offsetExists: ArgumentCountError: WeakMap::offsetExists() expects exactly 1 argument, 2 given
offsetGet: ArgumentCountError: WeakMap::offsetGet() expects exactly 1 argument, 2 given
offsetUnset: ArgumentCountError: WeakMap::offsetUnset() expects exactly 1 argument, 2 given
offsetExists0: ArgumentCountError: WeakMap::offsetExists() expects exactly 1 argument, 0 given
offsetGet0: ArgumentCountError: WeakMap::offsetGet() expects exactly 1 argument, 0 given
offsetUnset0: ArgumentCountError: WeakMap::offsetUnset() expects exactly 1 argument, 0 given
offsetExists_ok: true
offsetGet_ok: 1
offsetUnset_ok: 'n'
