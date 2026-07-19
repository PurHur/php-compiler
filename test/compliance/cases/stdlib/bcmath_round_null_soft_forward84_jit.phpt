--TEST--
stdlib bcround(null) soft-null on 8.4 JIT (#21006, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo 'bcround=', bcround(null, 0), "\n";
    echo 'empty=', bcround('', 0), "\n";
    echo 'ok=', bcround('1.5', 0), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    bcround('abc', 0);
    echo "invalid uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bcround=0
empty=0
ok=2
bcround(): Argument #1 ($num) is not well-formed
