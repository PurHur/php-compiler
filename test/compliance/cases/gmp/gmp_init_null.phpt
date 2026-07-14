--TEST--
gmp_init(null) coerces to zero GMP int (ext/gmp/gmp.c Z_PARAM_STR_OR_LONG; #18946)
--FILE--
<?php
$z = gmp_init(null);
echo gmp_strval($z), "\n";
echo gmp_strval(gmp_add($z, gmp_init('1'))), "\n";
try {
    gmp_init([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
0
1
gmp_init(): Argument #1 ($num) must be of type GMP|string|int, array given
