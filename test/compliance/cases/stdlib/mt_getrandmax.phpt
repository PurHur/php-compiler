--TEST--
stdlib mt_getrandmax() — MT upper bound beside mt_rand() (#17228, ext/random/random.c)
--FILE--
<?php
echo function_exists('mt_rand') ? "mt_rand=yes\n" : "mt_rand=no\n";
echo function_exists('mt_getrandmax') ? "mt_getrandmax=yes\n" : "mt_getrandmax=no\n";
echo mt_getrandmax(), "\n";
try {
    mt_getrandmax(1);
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
mt_rand=yes
mt_getrandmax=yes
2147483647
mt_getrandmax() expects exactly 0 arguments, 1 given
