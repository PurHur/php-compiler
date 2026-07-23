<?php
enum E { case A; }
try {
    new ReflectionEnumUnitCase(E::A);
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    new ReflectionFunction();
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
