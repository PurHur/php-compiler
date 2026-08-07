<?php

declare(strict_types=1);

/**
 * Repro #28402 — Zend builtin attribute classes must be final
 * (php-src Zend/zend_attributes.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28402_builtin_attr_final.php
 */
foreach ([
    AllowDynamicProperties::class,
    ReturnTypeWillChange::class,
    SensitiveParameter::class,
    Override::class,
    Deprecated::class,
] as $c) {
    var_export((new ReflectionClass($c))->isFinal());
    echo " $c\n";
}
try {
    eval('class X extends AllowDynamicProperties {}');
    echo "EXTENDS_OK\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
