--TEST--
empty($arr[$float]) Implicit conversion Deprecated once (#29560)
--FILE--
<?php
function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');
$a = [1 => 'x'];
var_export(isset($a[1.5]));
echo "\n";
var_export(empty($a[1.5]));
echo "\n";
--EXPECT--
D:Implicit conversion from float 1.5 to int loses precision
true
D:Implicit conversion from float 1.5 to int loses precision
false
