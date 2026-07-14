--TEST--
gmp_init() rejects non-scalar operands with typed message (ext/gmp/gmp.c; issue #18946)
--FILE--
<?php
try {
    gmp_init([]);
} catch (\TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
gmp_init(): Argument #1 ($num) must be of type GMP|string|int, array given
