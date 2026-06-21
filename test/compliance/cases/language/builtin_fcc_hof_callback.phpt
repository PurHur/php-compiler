--TEST--
language: inline builtin first-class callable in HOF callback position (#10473, zend_compile.c)
--FILE--
<?php
var_export(array_map(strtoupper(...), ['a', 'b']));
echo "\n";
var_export(array_filter(['a', '', 'c'], strlen(...)));
echo "\n";
echo call_user_func_array(strtoupper(...), ['b']), "\n";
--EXPECT--
array (
  0 => 'A',
  1 => 'B',
)
array (
  0 => 'a',
  2 => 'c',
)
B
