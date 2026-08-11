<?php
/**
 * Issue #30075 — property ++/-- on true|false uses increment/decrement Error
 * with zend_zval_value_name (true/false), not assign…on bool.
 * php-src: Zend/zend_object_handlers.c; Zend/zend_vm_def.h ZEND_*_INC/DEC_OBJ
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_30075_bool_prop_incdec_labels.php
 */
error_reporting(E_ALL);

$x = false;
try {
    $x->a++;
} catch (Throwable $e) {
    echo 'POST_INC:', $e->getMessage(), "\n";
}
$x = true;
try {
    $x->a--;
} catch (Throwable $e) {
    echo 'POST_DEC:', $e->getMessage(), "\n";
}
$x = false;
try {
    ++$x->a;
} catch (Throwable $e) {
    echo 'PRE_INC:', $e->getMessage(), "\n";
}
$x = true;
try {
    --$x->a;
} catch (Throwable $e) {
    echo 'PRE_DEC:', $e->getMessage(), "\n";
}
