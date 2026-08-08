--TEST--
stdlib array_find family by-ref callback Warning (#28928, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
// Assign ini_set return — bare discard before array_find misbinds args under PROFILE=8.4.
$__ = ini_set('display_errors', '1');

function pred(&$v) {
    return $v === 2;
}

$r = array_find([1, 2, 3], function (&$v) { return $v === 2; });
echo 'find=';
var_export($r);
echo "\n";

$r = array_find_key([1, 2, 3], function (&$v) { return $v === 2; });
echo 'find_key=';
var_export($r);
echo "\n";

$r = array_any([1, 2, 3], function (&$v) { return $v === 2; });
echo 'any=';
var_export($r);
echo "\n";

$r = array_all([1, 2, 3], function (&$v) { return $v > 0; });
echo 'all=';
var_export($r);
echo "\n";

$r = array_find([1, 2, 3], 'pred');
echo 'user=';
var_export($r);
echo "\n";
?>
--EXPECTF--
Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d
find=2

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d
find_key=1

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d
any=true

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: {closure}(): Argument #1 ($v) must be passed by reference, value given in %s on line %d
all=true

Warning: pred(): Argument #1 ($v) must be passed by reference, value given in %s on line %d

Warning: pred(): Argument #1 ($v) must be passed by reference, value given in %s on line %d
user=2
