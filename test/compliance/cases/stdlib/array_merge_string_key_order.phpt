--TEST--
stdlib array_merge() inline array_keys() trailing arg preserves string-key order (#13760, #13775, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

var_export(array_merge(['a' => 1], array_keys(['b' => 2])));
echo "\n";
--EXPECT--
array (
  'a' => 1,
  0 => 'b',
)
