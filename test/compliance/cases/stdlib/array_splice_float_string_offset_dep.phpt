--TEST--
array_splice() float-string offset Implicit conversion Deprecated (#29706)
--FILE--
<?php
function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');
$a = [1, 2, 3, 4];
array_splice($a, '1.5', 1);
var_export($a);
echo "\n";
$b = [1, 2, 3, 4];
array_splice($b, 1.5, 1);
var_export($b);
echo "\n";
$c = [1, 2, 3, 4];
array_splice($c, '1.0', 1);
var_export($c);
echo "\n";
$d = [1, 2, 3, 4];
array_splice($d, '2', 1);
var_export($d);
echo "\n";
--EXPECT--
D:Implicit conversion from float-string "1.5" to int loses precision
array (
  0 => 1,
  1 => 3,
  2 => 4,
)
D:Implicit conversion from float 1.5 to int loses precision
array (
  0 => 1,
  1 => 3,
  2 => 4,
)
array (
  0 => 1,
  1 => 3,
  2 => 4,
)
array (
  0 => 1,
  1 => 2,
  2 => 4,
)
