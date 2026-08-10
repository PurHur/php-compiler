--TEST--
$arr[$float] ?? / ??= Implicit conversion Deprecated once when set (#29664)
--FILE--
<?php
function capture(int $errno, string $message): bool
{
    echo ($errno === E_DEPRECATED ? 'D:' : 'W:'), $message, "\n";

    return true;
}
set_error_handler('capture');
echo "?? present:\n";
$a = [1, 2, 3];
echo ($a[1.5] ?? 'x'), "\n";
echo "??= present:\n";
$b = [1, 2, 3];
$b[1.5] ??= 9;
echo $b[1], "\n";
echo "??= missing:\n";
$c = [];
$c[1.5] ??= 9;
var_export($c);
echo "\n";
echo "?? missing:\n";
$d = [];
echo ($d[1.5] ?? 'x'), "\n";
echo "empty/isset:\n";
$e = [1 => 'x'];
var_export(isset($e[1.5]));
echo "\n";
var_export(empty($e[1.5]));
echo "\n";
--EXPECT--
?? present:
D:Implicit conversion from float 1.5 to int loses precision
2
??= present:
D:Implicit conversion from float 1.5 to int loses precision
2
??= missing:
D:Implicit conversion from float 1.5 to int loses precision
D:Implicit conversion from float 1.5 to int loses precision
array (
  1 => 9,
)
?? missing:
D:Implicit conversion from float 1.5 to int loses precision
x
empty/isset:
D:Implicit conversion from float 1.5 to int loses precision
true
D:Implicit conversion from float 1.5 to int loses precision
false
