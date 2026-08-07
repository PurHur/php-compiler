<?php

declare(strict_types=1);

/**
 * Repro #28390 — WeakReference / WeakMap must be final (php-src Zend/zend_weakrefs.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/weakref_final.php
 */
var_export((new ReflectionClass(WeakReference::class))->isFinal());
echo "\n";
var_export((new ReflectionClass(WeakMap::class))->isFinal());
echo "\n";
eval('class X extends WeakMap {}');
echo "EXTENDS_OK\n";
