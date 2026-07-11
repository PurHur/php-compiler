--TEST--
foreach/array_walk by-ref string assignment persists (#13318, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

$a = ['a' => 1, 'b' => 2];
foreach ($a as $k => &$v) {
    $v = $k . $v;
}
unset($v);
var_export($a);
echo "\n";

$b = [1, 2];
foreach ($b as &$v) {
    $v = 'n' . $v;
}
unset($v);
var_export($b);
echo "\n";

$arr = ['x' => 5, 'y' => 10];
array_walk($arr, static function (mixed &$value, mixed $key): void {
    $value = $key . $value;
});
var_export($arr);
echo "\n";
--EXPECT--
array (
  'a' => 'a1',
  'b' => 'b2',
)
array (
  0 => 'n1',
  1 => 'n2',
)
array (
  'x' => 'x5',
  'y' => 'y10',
)
