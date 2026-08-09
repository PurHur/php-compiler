<?php
/**
 * Issue #29532 — object as array key must TypeError (Illegal offset type).
 * Inline `new` inside try must not be remapped to null→"" via merge-echo phi.
 */
error_reporting(E_ALL);

class T
{
}

class S
{
    public function __toString(): string
    {
        echo "TOSTRING\n";

        return 'k';
    }
}

$a = [];
try {
    $a[new T()] = 1;
    echo "AFTER_PLAIN\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$b = [];
try {
    $b[new S()] = 1;
    echo 'keys=', json_encode(array_keys($b)), "\n";
    echo "AFTER_STR\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$c = [];
$o = new T();
try {
    $c[$o] = 1;
    echo "AFTER_VAR\n";
} catch (Throwable $e) {
    echo 'var ', get_class($e), ': ', $e->getMessage(), "\n";
}

echo "done\n";
