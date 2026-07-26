--TEST--
chunk_split/str_split named string/length/separator arguments (JIT, issue #23206)
--FILE--
<?php
var_export(chunk_split(string: 'abcd', length: 2, separator: '|'));
echo PHP_EOL;
var_export(str_split(string: 'abcd', length: 2));
echo PHP_EOL;
--EXPECT--
'ab|cd|'
array (
  0 => 'ab',
  1 => 'cd',
)
