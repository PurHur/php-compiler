--TEST--
stdlib grapheme_str_split() JIT — compile-time fold (#5958, #6246)
--FILE--
<?php
echo (int) function_exists('grapheme_str_split'), "\n";

$one = grapheme_str_split("e\xCC\x81");
var_export($one);
echo "\n";

$ascii = grapheme_str_split('abc');
var_export($ascii);
echo "\n";

$chunked = grapheme_str_split('abcdef', 2);
var_export($chunked);
echo "\n";
--EXPECT--
1
array (
  0 => 'é',
)
array (
  0 => 'a',
  1 => 'b',
  2 => 'c',
)
array (
  0 => 'ab',
  1 => 'cd',
  2 => 'ef',
)
