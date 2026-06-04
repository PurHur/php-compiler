--TEST--
Language: array write with enum case offset throws TypeError (#5594)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    $a = [];
    $a[E::A] = 1;
    echo "no-exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
enum U { case B; }
try {
    $b = [];
    $b[U::B] = 2;
    echo "no-exception-unit\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Illegal offset type
TypeError: Illegal offset type
