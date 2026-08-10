<?php
/**
 * Issue #29592 — $bool::class TypeError uses true/false under PROFILE≥8.3
 * (sibling of #29576 string wording; shared EnumCaseSupport formatter)
 * php-src: Zend/zend_vm_def.h ZEND_FETCH_CLASS_NAME; Zend/zend_API.c zend_zval_value_name()
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_29592_bool_class_typeerror.php
 */
error_reporting(E_ALL);
foreach ([true, false] as $b) {
    try {
        echo $b::class, "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
