<?php
// Repro #23063 — Zend refuses serialize()/unserialize() of WeakReference.
$o = new stdClass();
$w = WeakReference::create($o);
try {
    echo serialize($w), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:13:"WeakReference":0:{}');
    echo "unserialize:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
