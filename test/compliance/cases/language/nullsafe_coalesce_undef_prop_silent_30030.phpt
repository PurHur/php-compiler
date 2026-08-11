--TEST--
Language: nullsafe ?-> under ?? suppresses Undefined property Warning (#30030)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$b = new stdClass();
echo "nullsafe-coalesce:\n";
var_export($b?->foo ?? 'no');
echo "\n";

echo "plain-coalesce:\n";
var_export($b->foo ?? 'no');
echo "\n";

echo "bare-nullsafe:\n";
var_export($b?->foo);
echo "\n";

$null = null;
echo "null-recv-coalesce:\n";
var_export($null?->foo ?? 'no');
echo "\n";
--EXPECT--
nullsafe-coalesce:
'no'
plain-coalesce:
'no'
bare-nullsafe:
W:Undefined property: stdClass::$foo
NULL
null-recv-coalesce:
'no'
