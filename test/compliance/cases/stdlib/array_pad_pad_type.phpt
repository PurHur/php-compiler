--TEST--
stdlib array_pad() ARRAY_PAD_* pad type (PHP 8.4+, #14993, #22786, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!defined('ARRAY_PAD_RIGHT')) {
    die('skip ARRAY_PAD_* not on forward 8.4 profile');
}

$r = array_pad([1, 2], 5, 0, ARRAY_PAD_RIGHT);
$l = array_pad([1, 2], 5, 0, ARRAY_PAD_LEFT);
$b = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);

var_export($r);
echo "\n";
var_export($l);
echo "\n";
var_export($b);
echo "\n";
echo (new ReflectionFunction('array_pad'))->getNumberOfParameters(), "\n";
?>
--EXPECT--
array (
  0 => 1,
  1 => 2,
  2 => 0,
  3 => 0,
  4 => 0,
)
array (
  0 => 0,
  1 => 0,
  2 => 0,
  3 => 1,
  4 => 2,
)
array (
  0 => 0,
  1 => 0,
  2 => 1,
  3 => 2,
  4 => 0,
)
4
