<?php

declare(strict_types=1);

/**
 * Issue #12598 — CompileTime/DelayedTargetValidation must not register on Zend 8.2 reference profile.
 */

foreach (['CompileTime', 'DelayedTargetValidation'] as $class) {
    if (class_exists($class, false)) {
        echo "fail: {$class} attribute class registered on Zend 8.2 reference profile\n";
        exit(1);
    }
}

echo "ok\n";
