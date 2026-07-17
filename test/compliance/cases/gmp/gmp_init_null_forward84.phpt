--TEST--
gmp_init(null) TypeError on 8.4 forward profile (#20210, ext/gmp/gmp.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $z = gmp_init(null);
    echo 'COERCED ', gmp_strval($z), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo gmp_strval(gmp_init(0)), "\n";
try {
    gmp_init([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
gmp_init(): Argument #1 ($num) must be of type GMP|string|int, null given
0
gmp_init(): Argument #1 ($num) must be of type GMP|string|int, array given
