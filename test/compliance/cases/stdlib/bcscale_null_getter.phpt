--TEST--
stdlib bcscale(null) — nullable getter parity (ext/bcmath/bcmath.stub.php, #20974)
--FILE--
<?php
if (!function_exists('bcscale')) {
    echo "missing\n";
    exit(1);
}
echo bcscale(3), "\n";
echo bcscale(null), "\n";
echo bcscale(), "\n";
try {
    bcscale('nope');
    echo "string-ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
0
3
3
bcscale(): Argument #1 ($scale) must be of type ?int, string given
ok
