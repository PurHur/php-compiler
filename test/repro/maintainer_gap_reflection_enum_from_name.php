<?php

declare(strict_types=1);

// Repro #16877 — ReflectionEnum::fromName() (ext/reflection/php_reflection.c).
if (!method_exists(ReflectionEnum::class, 'fromName')) {
    echo "missing\n";
    exit(1);
}

enum E { case A; case B; }

$case = ReflectionEnum::fromName('E', 'A');
if (!($case instanceof ReflectionEnumUnitCase)) {
    echo 'fail type='.get_debug_type($case)."\n";
    exit(1);
}
if ('A' !== $case->getName()) {
    echo 'fail name='.$case->getName()."\n";
    exit(1);
}

try {
    ReflectionEnum::fromName('E', 'NoSuchCase');
    echo "bad case\n";
    exit(1);
} catch (ReflectionException $e) {
    // expected
}

try {
    ReflectionEnum::fromName('NotAnEnum', 'x');
    echo "bad enum\n";
    exit(1);
} catch (ReflectionException $e) {
    // expected
}

echo "ok\n";
