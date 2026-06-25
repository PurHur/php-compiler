<?php
declare(strict_types=1);

// php-src ext/standard/basic_functions.c — get_declared_classes() includes CE_INTERNAL classes (#11813).

$required = [
    'stdClass',
    'Exception',
    'Error',
    'Closure',
    'Generator',
    'ArrayObject',
    'WeakMap',
    'WeakReference',
];

$classes = get_declared_classes();
$missing = [];
foreach ($required as $name) {
    if (!class_exists($name, false)) {
        $missing[] = $name . '(class_exists)';
        continue;
    }
    if (!in_array($name, $classes, true)) {
        $missing[] = $name;
    }
}

if ([] !== $missing) {
    echo 'fail missing=' . implode(',', $missing) . "\n";
    exit(1);
}

echo "ok\n";
