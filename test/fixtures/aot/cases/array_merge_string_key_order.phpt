--TEST--
AOT array_merge() leading array + inline array_keys() (#13760)
--FILE--
<?php
var_export(array_merge(['a' => 1], array_keys(['b' => 2])));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  0 => 'b',
)
