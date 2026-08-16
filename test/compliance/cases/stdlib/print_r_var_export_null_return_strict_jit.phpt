--TEST--
stdlib print_r/var_export(null $return) TypeError under strict_types JIT (#31337, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);
try {
    print_r([1], null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    var_export(1, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
print_r(): Argument #2 ($return) must be of type bool, null given
var_export(): Argument #2 ($return) must be of type bool, null given
