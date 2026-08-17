--TEST--
grapheme_str_split named string/length (JIT, issue #24579)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(grapheme_str_split(string: 'abcdef', length: 2));
echo PHP_EOL;
var_export(grapheme_str_split('abcdef', 2));
echo PHP_EOL;
?>
--EXPECT--
array (
  0 => 'ab',
  1 => 'cd',
  2 => 'ef',
)
array (
  0 => 'ab',
  1 => 'cd',
  2 => 'ef',
)
