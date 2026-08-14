--TEST--
language: WeakReference::get excess argc → ArgumentCountError JIT (#30925)
--FILE--
<?php
$o = new stdClass();
$w = WeakReference::create($o);
try {
    var_export($w->get(1) === $o);
    echo " ACCEPTED\n";
} catch (Throwable $e) {
    echo 'get ', get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_get=', $w->get() === $o ? "y\n" : "n\n";
unset($o);
echo 'ok_dead=', var_export($w->get(), true), "\n";
--EXPECT--
get ArgumentCountError: WeakReference::get() expects exactly 0 arguments, 1 given
ok_get=y
ok_dead=NULL
