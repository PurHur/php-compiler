--TEST--
stdlib print_r/var_export(null $return) TypeError under strict_types (#31337, ext/standard/var.c)
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
// omit-arg still defaults to false (echo mode)
print_r(true);
echo "\n";
var_export(2);
echo "\n";
--EXPECT--
print_r(): Argument #2 ($return) must be of type bool, null given
var_export(): Argument #2 ($return) must be of type bool, null given
1
2
