--TEST--
stdlib hex2bin() — $strict throws Error (issue #4966, #10072)
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
} catch (Error $e) {
    echo 'strict ok:', $e->getMessage(), "\n";
}
try {
    hex2bin('abc', true);
    echo "odd no throw\n";
} catch (Error $e) {
    echo 'odd strict:', $e->getMessage(), "\n";
}
--EXPECT--
'AB'
W:Input string must be hexadecimal string
false
strict ok:Input string must be hexadecimal string
odd strict:Hexadecimal input string must have an even length
