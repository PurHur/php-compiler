--TEST--
stdlib mt_getrandmax() JIT — MT_RAND_MAX parity (#17228, ext/random/random.c)
--FILE--
<?php
echo function_exists('mt_getrandmax') ? "mt_getrandmax=yes\n" : "mt_getrandmax=no\n";
echo mt_getrandmax(), "\n";
echo getrandmax() === mt_getrandmax() ? "same_as_getrandmax=yes\n" : "same_as_getrandmax=no\n";
try {
    mt_getrandmax(1);
    echo "argcount=uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mt_getrandmax=yes
2147483647
same_as_getrandmax=yes
mt_getrandmax() expects exactly 0 arguments, 1 given
