--TEST--
stdlib array_multisort() inline first array — companion assign-in-call keeps order (#15151)
--FILE--
<?php
array_multisort([3, 1, 2], $labels = ['c', 'a', 'b']);
var_export($labels);
echo "\n";
--EXPECT--
array (
  0 => 'c',
  1 => 'a',
  2 => 'b',
)
