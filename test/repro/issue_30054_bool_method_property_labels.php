<?php
/**
 * Issue #30054 — method/property on true|false use zend_zval_value_name
 * (true/false), not "bool". Sibling of #29592 (::class).
 * php-src: Zend/zend_execute.c; Zend/zend_object_handlers.c
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_30054_bool_method_property_labels.php
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $message): bool {
    if (E_WARNING === $errno) {
        echo 'W:', $message, "\n";
    }

    return true;
});

foreach ([false, true] as $x) {
    try {
        $x->foo();
    } catch (Throwable $e) {
        echo 'M:', $e->getMessage(), "\n";
    }
}
foreach ([false, true] as $x) {
    $v = $x->foo;
    echo 'P:', var_export($v, true), "\n";
}
$x = 1;
$v = $x->foo;
echo 'I:', var_export($v, true), "\n";
