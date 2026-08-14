<?php
// Repro for #30925 — WeakReference::get() excess argc → ArgumentCountError
$o = new stdClass();
$w = WeakReference::create($o);
try {
    var_export($w->get(1) === $o);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok_get=', $w->get() === $o ? "y\n" : "n\n";
unset($o);
echo 'ok_dead=', var_export($w->get(), true), "\n";
