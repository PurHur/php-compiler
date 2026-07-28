--TEST--
ARRAY_PAD_* + array_pad() 4th arg never on PROFILE=8.4 (#24002, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['ARRAY_PAD_LEFT', 'ARRAY_PAD_RIGHT', 'ARRAY_PAD_BOTH'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "
";
}
echo 'enum=', enum_exists('ArrayPadType', false) ? '1' : '0', "
";
echo (new ReflectionFunction('array_pad'))->getNumberOfParameters(), "
";
try {
    array_pad([1], 3, 0, 0);
    echo "no error
";
} catch (Throwable $e) {
    echo get_class($e), "
";
    echo $e->getMessage(), "
";
}
// Sign-of-length padding still matches Zend
var_export(array_pad([1, 2], 5, 0));
echo "
";
var_export(array_pad([1, 2], -5, 0));
echo "
";
?>
--EXPECT--
ARRAY_PAD_LEFT=0
ARRAY_PAD_RIGHT=0
ARRAY_PAD_BOTH=0
enum=0
3
ArgumentCountError
array_pad() expects exactly 3 arguments, 4 given
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
