--TEST--
stdlib bcadd(null) soft-null on 8.4 JIT (#20973, ext/bcmath/bcmath.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo 'bcadd=', bcadd(null, '1'), "\n";
    echo 'bcmul=', bcmul(null, '1'), "\n";
    echo 'bcsqrt=', bcsqrt(null), "\n";
    echo 'empty=', bcadd('', '1'), "\n";
    echo 'ok=', bcadd('2', '3'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    bcadd('abc', '1');
    echo "invalid uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
bcadd=1
bcmul=0
bcsqrt=0
empty=1
ok=5
bcmath function(): Argument is not a valid number
