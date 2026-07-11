--TEST--
stdlib array builtins — TypeError for null array argument JIT (#11985, ext/standard/array.c)
--JIT--
--FILE--
<?php
$checks = [
    'array_unique' => 'array_unique(): Argument #1 ($array) must be of type array, null given',
    'array_reverse' => 'array_reverse(): Argument #1 ($array) must be of type array, null given',
    'array_change_key_case' => 'array_change_key_case(): Argument #1 ($array) must be of type array, null given',
    'array_filter' => 'array_filter(): Argument #1 ($array) must be of type array, null given',
];
foreach ($checks as $fn => $expected) {
    try {
        $fn(null);
        echo "{$fn}: uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
array_unique: array_unique(): Argument #1 ($array) must be of type array, null given
array_reverse: array_reverse(): Argument #1 ($array) must be of type array, null given
array_change_key_case: array_change_key_case(): Argument #1 ($array) must be of type array, null given
array_filter: array_filter(): Argument #1 ($array) must be of type array, null given
