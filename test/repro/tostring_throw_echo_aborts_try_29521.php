<?php
/** Repro #29521 — __toString throw during echo/print/(string) must not continue try body. */
error_reporting(E_ALL);
class A {
    public function __toString() {
        throw new RuntimeException('no');
    }
}

try {
    echo new A;
    echo "AFTER_ECHO\n";
} catch (Throwable $e) {
    echo 'caught_echo:', $e->getMessage(), "\n";
}

try {
    print new A;
    echo "AFTER_PRINT\n";
} catch (Throwable $e) {
    echo 'caught_print:', $e->getMessage(), "\n";
}

try {
    $s = (string) new A;
    echo "AFTER_CAST:$s\n";
} catch (Throwable $e) {
    echo 'caught_cast:', $e->getMessage(), "\n";
}

try {
    throw new RuntimeException('plain');
    echo "AFTER_THROW\n";
} catch (Throwable $e) {
    echo 'caught_plain:', $e->getMessage(), "\n";
}

echo "DONE\n";
