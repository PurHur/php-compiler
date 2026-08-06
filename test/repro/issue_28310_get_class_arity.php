<?php

declare(strict_types=1);

/**
 * Repro #28310 — get_class Reflection arity 1; no phantom allow_string (Zend stub).
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28310_get_class_arity.php
 */

$r = new ReflectionFunction('get_class');
echo 'argc=', $r->getNumberOfParameters(), "\n";
foreach ($r->getParameters() as $p) {
    echo $p->getName(), "\n";
}

try {
    get_class(allow_string: true);
    echo "named-ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    echo get_class(new stdClass(), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
