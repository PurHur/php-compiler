--TEST--
stdlib hex2bin() — second argument rejected (php-src arity 1, #27763 / #13116)
--FILE--
<?php
var_export(hex2bin('4142'));
echo "\n";
function hex2bin_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('hex2bin_warn_capture');
var_export(hex2bin('41zz'));
echo "\n";
try {
    hex2bin('41zz', true);
    echo "no throw\n";
} catch (ArgumentCountError $e) {
    echo 'arity:', $e->getMessage(), "\n";
}
try {
    hex2bin('abc', true);
    echo "odd no throw\n";
} catch (ArgumentCountError $e) {
    echo 'odd arity:', $e->getMessage(), "\n";
}
--EXPECT--
'AB'
W:hex2bin(): Input string must be hexadecimal string
false
arity:hex2bin() expects exactly 1 argument, 2 given
odd arity:hex2bin() expects exactly 1 argument, 2 given
