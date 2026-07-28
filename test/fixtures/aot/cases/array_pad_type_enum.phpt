--TEST--
AOT: ArrayPadType enum never on PROFILE=8.4 (#24002, #17240)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo enum_exists('ArrayPadType', false) ? "enum=1
" : "enum=0
";
echo class_exists('ArrayPadType', false) ? "class=1
" : "class=0
";
var_export(array_pad([1], 4, 0));
echo "
";
?>
--EXPECT--
enum=0
class=0
array (
  0 => 1,
  1 => 0,
  2 => 0,
  3 => 0,
)
