<?php
/**
 * Repro #29623 — $resource::class must TypeError like Zend (not echo "Resource").
 */
error_reporting(E_ALL);
$f = fopen('php://memory', 'r');
try {
    echo $f::class, "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
fclose($f);
try {
    echo $f::class, "\n";
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
