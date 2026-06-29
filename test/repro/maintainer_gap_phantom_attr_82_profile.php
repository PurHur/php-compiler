<?php

declare(strict_types=1);

// Zend 8.2 reference profile: EnumCases/NoDiscard must not be registered (#13706).
foreach (['EnumCases', 'NoDiscard'] as $class) {
    if (class_exists($class, false)) {
        echo "fail: phantom builtin attribute class {$class} on 8.2 reference profile\n";
        exit(1);
    }
}

// PHP 8.0+ SensitiveParameter remains available on 8.2 reference.
if (!class_exists('SensitiveParameter', false)) {
    echo "fail: SensitiveParameter missing on 8.2 reference profile\n";
    exit(1);
}

echo "ok\n";
