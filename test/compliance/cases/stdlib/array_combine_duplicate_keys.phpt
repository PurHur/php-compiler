--TEST--
stdlib array_combine() duplicate keys keep last value (ext/standard/array.c)
--FILE--
<?php
echo var_export(array_combine([1, 1], ['a', 'b'])), "\n";
echo var_export(array_combine(['k', 'k'], ['a', 'b'])), "\n";
--EXPECT--
array (
  1 => 'b',
)
array (
  'k' => 'b',
)
