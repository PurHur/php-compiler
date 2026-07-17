--TEST--
stdlib grapheme_str_split() JIT — runtime operands (#19964, ext/intl/grapheme)
--FILE--
<?php
$string = "e\xCC\x81";
$one = grapheme_str_split($string);
var_export($one);
echo "\n";

$ascii = 'abc';
$parts = grapheme_str_split($ascii);
var_export($parts);
echo "\n";

$chunked = grapheme_str_split('abcdef', 2);
var_export($chunked);
echo "\n";
--EXPECT--
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
