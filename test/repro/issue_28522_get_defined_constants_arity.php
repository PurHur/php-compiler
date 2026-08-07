<?php

declare(strict_types=1);

/**
 * Issue #28522 — get_defined_constants Reflection arity 1 + ArgumentCountError;
 * no phantom $category on PROFILE≥8.4 (php-src Zend/zend_builtin_functions.stub.php).
 */

$r = new ReflectionFunction('get_defined_constants');
echo 'arity=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo '$', $p->getName(), "\n";
}
try {
    get_defined_constants(false, 'user');
    echo "argc2=ok\n";
} catch (Throwable $e) {
    echo 'argc2=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    get_defined_constants(category: 'Core');
    echo "named=ok\n";
} catch (Throwable $e) {
    echo 'named=', get_class($e), ': ', $e->getMessage(), "\n";
}
