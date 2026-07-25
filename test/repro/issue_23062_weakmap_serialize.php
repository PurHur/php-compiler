<?php
// Repro #23062 — Zend refuses serialize()/unserialize() of WeakMap.
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 1;
try {
    echo serialize($wm), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:7:"WeakMap":0:{}');
    echo "unserialize:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
