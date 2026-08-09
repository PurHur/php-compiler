<?php

/**
 * Issue #29550 — resource used as array offset: Zend E_WARNING + int cast, not TypeError.
 *
 * php-src: Zend/zend_hash.c / Zend/zend_execute.c
 */
error_reporting(E_ALL);
$a = [];
$r = fopen('php://memory', 'r');
$id = (int) $r;
try {
    $a[$r] = 1;
    echo isset($a[$id]) ? "set-ok\n" : "set-miss\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo isset($a[$r]) ? "isset-ok\n" : "isset-miss\n";
} catch (Throwable $e) {
    echo 'isset:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unset($a[$r]);
    echo array_key_exists($id, $a) ? "unset-miss\n" : "unset-ok\n";
} catch (Throwable $e) {
    echo 'unset:', get_class($e), ':', $e->getMessage(), "\n";
}
fclose($r);
