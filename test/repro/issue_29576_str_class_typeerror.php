<?php
/**
 * Issue #29576 — $str::class TypeError uses zend_zval_value_name under PROFILE≥8.3
 * php-src: Zend/zend_vm_def.h ZEND_FETCH_CLASS_NAME; Zend/zend_API.c zend_zval_value_name()
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_29576_str_class_typeerror.php
 */
error_reporting(E_ALL);
$s = 'stdClass';
try {
    echo $s::class;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
