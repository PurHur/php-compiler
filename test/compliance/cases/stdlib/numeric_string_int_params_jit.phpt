--TEST--
stdlib array_splice/substr_count/array_change_key_case JIT — numeric-string int params (#4259)
--FILE--
<?php
$a = [10, 20, 30, 40];
var_export(array_splice($a, '1', '2'));
echo "\n";
echo substr_count('abababa', 'ab', '2', '4'), "\n";
var_export(array_change_key_case(['Foo' => 1], '0'));
echo "\n";
--EXPECT--
array (
  0 => 20,
  1 => 30,
)
2
array (
  'foo' => 1,
)
