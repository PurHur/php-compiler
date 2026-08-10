--TEST--
isset()/empty() string float dim — Implicit conversion Deprecated once (#29557)
--FILE--
<?php
function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');
$s = 'ab';
var_export(isset($s[1.5]));
echo "\n";
var_export(empty($s[1.5]));
echo "\n";
var_export($s[1.5]);
echo "\n";
--EXPECT--
D:Implicit conversion from float 1.5 to int loses precision
true
D:Implicit conversion from float 1.5 to int loses precision
false
W:String offset cast occurred
'b'
