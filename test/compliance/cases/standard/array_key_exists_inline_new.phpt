--TEST--
standard array_key_exists() inline new operand — TypeError names ArrayObject (#18456)
--FILE--
<?php
$expected = 'array_key_exists(): Argument #2 ($array) must be of type array, ArrayObject given';
try {
    array_key_exists(0, new ArrayObject([1]));
    echo "inline new: unexpected success\n";
} catch (TypeError $e) {
    echo 'inline new: ', $e->getMessage(), "\n";
}
$o = new ArrayObject([1]);
try {
    array_key_exists(0, $o);
    echo "variable: unexpected success\n";
} catch (TypeError $e) {
    echo 'variable: ', $e->getMessage(), "\n";
}
--EXPECT--
inline new: array_key_exists(): Argument #2 ($array) must be of type array, ArrayObject given
variable: array_key_exists(): Argument #2 ($array) must be of type array, ArrayObject given
