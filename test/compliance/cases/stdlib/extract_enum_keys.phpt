--TEST--
stdlib extract() enum case array keys — TypeError Illegal offset type (#5756, ext/standard/basic_functions.c)
--FILE--
<?php
enum E { case A; }

try {
    extract([E::A => 1]);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

try {
    $a = [];
    $a[E::A] = 1;
    extract($a);
    echo "ok2\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Illegal offset type
TypeError: Illegal offset type
