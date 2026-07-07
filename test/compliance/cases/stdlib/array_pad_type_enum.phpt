--TEST--
stdlib array_pad() ArrayPadType enum 4th arg (PHP 8.4+, #17240, ext/standard/array.c)
--FILE--
<?php
if (!enum_exists('ArrayPadType', false)) {
    die('skip ArrayPadType not on reference profile');
}

$positive = array_pad([1], 4, 0, ArrayPadType::Positive);
$negative = array_pad([1], 4, 0, ArrayPadType::Negative);
$both = array_pad([1, 2], 5, 0, ARRAY_PAD_BOTH);

var_export($positive);
echo "\n";
var_export($negative);
echo "\n";
var_export($both);
echo "\n";
?>
--EXPECT--
array (
  0 => 1,
  1 => 0,
  2 => 0,
  3 => 0,
)
array (
  0 => 0,
  1 => 0,
  2 => 0,
  3 => 1,
)
array (
  0 => 0,
  1 => 1,
  2 => 2,
  3 => 0,
  4 => 0,
)
