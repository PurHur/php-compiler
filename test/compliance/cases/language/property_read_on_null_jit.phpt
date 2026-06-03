--TEST--
Language: property read on null warns and returns null — JIT (#5276)
--JIT--
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$x = null;
var_export($x->y);
echo "\n";
--EXPECT--
W:Attempt to read property "y" on null
NULL
