--TEST--
stdlib grapheme_str_split() — grapheme cluster split (#5958, ext/intl/grapheme)
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

try {
    grapheme_str_split('abc', 0);
    echo "length0 uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
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
grapheme_str_split(): Argument #2 ($length) must be greater than 0 and less than or equal to 1073741823.
