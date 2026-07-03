<?php
declare(strict_types=1);

trait T {
    public function f(): int {
        return 1;
    }
}

class C {
    use T {
        f as private g;
    }
}

$rmViaClass = (new ReflectionClass(C::class))->getMethod('g');
if (!$rmViaClass->isPrivate()) {
    echo "getMethod: expected private\n";
    exit(1);
}
if ($rmViaClass->getDeclaringClass()->getName() !== C::class) {
    echo "getMethod: expected declaring class C, got " . $rmViaClass->getDeclaringClass()->getName() . "\n";
    exit(1);
}

try {
    $rmDirect = new ReflectionMethod(C::class, 'g');
} catch (ReflectionException $e) {
    echo "ReflectionMethod ctor failed: " . $e->getMessage() . "\n";
    exit(1);
}

if (!$rmDirect->isPrivate()) {
    echo "ReflectionMethod ctor: expected private\n";
    exit(1);
}
if ($rmDirect->getDeclaringClass()->getName() !== C::class) {
    echo "ReflectionMethod ctor: expected declaring class C, got " . $rmDirect->getDeclaringClass()->getName() . "\n";
    exit(1);
}

echo "ok\n";
