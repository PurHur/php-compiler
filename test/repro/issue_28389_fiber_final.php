<?php

declare(strict_types=1);

/**
 * Repro #28389 — Fiber / FiberError must be final (php-src Zend/zend_fibers.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28389_fiber_final.php
 */
echo 'fiber_isFinal=', var_export((new ReflectionClass(Fiber::class))->isFinal(), true), "\n";
echo 'fibererror_isFinal=', var_export((new ReflectionClass(FiberError::class))->isFinal(), true), "\n";
eval('class BadFiber extends Fiber {}');
echo "EXTENDED_FIBER_OK\n";
