--TEST--
ArrayPadType enum never registered on PROFILE=8.4 (#24002, #17240)
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
echo (new ReflectionFunction('array_pad'))->getNumberOfParameters(), "
";
?>
--EXPECT--
enum=0
class=0
3
