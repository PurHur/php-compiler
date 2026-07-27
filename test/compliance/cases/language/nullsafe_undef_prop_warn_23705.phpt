--TEST--
Language: nullsafe ?-> on non-null object still emits Undefined property Warning (#23705)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$o = new stdClass();
echo "direct:\n";
var_export($o->missing);
echo "\n";
echo "nullsafe:\n";
var_export($o?->missing);
echo "\n";

$n = null;
echo "null-recv:\n";
var_export($n?->missing);
echo "\n";

class C {}
$c = new C();
echo "nodyn:\n";
var_export($c?->missing);
echo "\n";
--EXPECT--
direct:
W:Undefined property: stdClass::$missing
NULL
nullsafe:
W:Undefined property: stdClass::$missing
NULL
null-recv:
NULL
nodyn:
W:Undefined property: C::$missing
NULL
