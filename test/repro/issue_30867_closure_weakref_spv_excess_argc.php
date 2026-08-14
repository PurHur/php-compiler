<?php
// Repro for #30867 — Closure::bindTo / WeakReference::create / SensitiveParameterValue::getValue excess argc
$f = function () { return 1; };
try {
    echo ($f->bindTo(null, 'static', 1))();
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$o = new stdClass();
try {
    echo WeakReference::create($o, 1)->get() === null ? "n\n" : "y\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$v = new SensitiveParameterValue('secret');
try {
    echo $v->getValue(1), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_bind=', ($f->bindTo(null, 'static'))(), "\n";
echo 'ok_weak=', WeakReference::create($o)->get() === $o ? "y\n" : "n\n";
echo 'ok_spv=', $v->getValue(), "\n";
