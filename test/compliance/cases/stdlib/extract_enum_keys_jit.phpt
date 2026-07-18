--TEST--
stdlib extract() enum case array keys JIT — TypeError Illegal offset type (#5756)
--FILE--
<?php
enum E { case A; }

try {
    extract([E::A => 1]);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: Illegal offset type
