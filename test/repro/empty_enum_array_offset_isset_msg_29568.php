<?php
/**
 * Issue #29568 — empty($arr[E::A]) TypeError must match isset() /
 * Zend "… in isset or empty" (not ordinary fetch "… on array").
 *
 * php-src: Zend/zend_execute.c — ZEND_ISSET_ISEMPTY_DIM_OBJ;
 * Zend/zend.c — zend_illegal_container_offset (PROFILE≥8.3 typed form).
 */
error_reporting(E_ALL);
enum E { case A; }
$a = [];
try {
    var_export(empty($a[E::A]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(isset($a[E::A]));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $a[E::A] = 1;
    echo "write ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
