--TEST--
stdlib pow(null) TypeError on 8.4 forward profile (#20951, ext/standard/math.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    var_export(pow(null, 2));
    echo "\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
try {
    var_export(exp(null));
    echo "\n";
} catch (TypeError $e) {
    echo "exp TypeError OK\n";
}
var_export(pow(2, 3));
echo "\n";
--EXPECT--
TypeError: pow(): Argument #1 ($num) must be of type float, null given
exp TypeError OK
8
