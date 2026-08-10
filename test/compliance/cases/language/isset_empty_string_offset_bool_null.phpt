--TEST--
isset()/empty() string bool/null dim — silent coerce; fetch still warns (#29558)
--FILE--
<?php
function capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('capture');
$s = 'ab';
$b = true;
$n = null;
var_export(isset($s[true]));
echo "\n";
var_export(isset($s[false]));
echo "\n";
var_export(isset($s[null]));
echo "\n";
var_export(empty($s[true]));
echo "\n";
var_export(isset($s[$b]));
echo "\n";
var_export(isset($s[$n]));
echo "\n";
var_export(empty($s[$b]));
echo "\n";
var_export($s[true]);
echo "\n";
var_export($s[$b]);
echo "\n";
--EXPECT--
true
true
true
false
true
true
false
W:String offset cast occurred
'b'
W:String offset cast occurred
'b'
