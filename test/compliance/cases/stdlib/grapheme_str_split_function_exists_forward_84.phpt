--TEST--
stdlib grapheme_str_split() — function_exists + split on PHP_COMPILER_PROFILE=8.4 (#22340)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('grapheme_str_split') ? '1' : '0';
echo "\n";
echo is_callable('grapheme_str_split') ? '1' : '0';
echo "\n";
$parts = grapheme_str_split('a😀b');
var_export($parts);
echo "\n";
--EXPECT--
1
1
array (
  0 => 'a',
  1 => '😀',
  2 => 'b',
)
