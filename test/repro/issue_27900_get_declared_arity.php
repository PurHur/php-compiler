<?php

declare(strict_types=1);

/**
 * Issue #27900 — get_declared_* Reflection arity 0 + ArgumentCountError on every profile.
 * php-src: Zend/zend_builtin_functions.stub.php
 */

foreach (['get_declared_classes', 'get_declared_interfaces', 'get_declared_traits'] as $n) {
    $r = new ReflectionFunction($n);
    echo $n, ' arity=', $r->getNumberOfParameters();
    foreach ($r->getParameters() as $p) {
        echo ' $', $p->getName();
    }
    echo "\n";
    try {
        $n(true);
        echo "  call(true)=ok\n";
    } catch (Throwable $e) {
        echo '  call(true)=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
