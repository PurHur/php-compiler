<?php

declare(strict_types=1);

// Issue #11902 — forward-compat builtin attribute classes must not phantom on Zend 8.2 reference.
$phantoms = [];
foreach (['Override', 'Deprecated', 'NoDiscard', 'DelayedTargetValidation', 'CompileTime'] as $class) {
    if (class_exists($class, false)) {
        $phantoms[] = $class;
    }
}

if ([] !== $phantoms) {
    echo 'fail: class_exists true for '.implode(', ', $phantoms).' on reference profile';
    exit(1);
}

echo "ok\n";
