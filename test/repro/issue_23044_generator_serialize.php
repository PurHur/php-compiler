<?php
// Repro #23044 — Zend refuses serialize()/unserialize() of Generator.
$c = function () {};
try {
    serialize($c);
    echo "closure:no-throw\n";
} catch (Throwable $e) {
    echo 'closure:', get_class($e), ':', $e->getMessage(), "\n";
}
$g = (function () { yield 1; })();
try {
    serialize($g);
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:9:"Generator":0:{}');
    echo "unserialize:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
