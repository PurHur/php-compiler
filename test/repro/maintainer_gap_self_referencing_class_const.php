<?php
// Circular class constants must Error with Zend's self-referencing message.

class A {
    public const X = self::Y;
    public const Y = self::X;
}

try {
    echo A::X;
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
