--TEST--
Language: unary - non-numeric string — JIT E_WARNING and int(0) (#5083)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');
var_export(-'0x10');
echo "\n";
--EXPECT--
W:A non-numeric value encountered
0
