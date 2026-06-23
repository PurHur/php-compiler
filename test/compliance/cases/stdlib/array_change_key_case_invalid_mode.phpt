--TEST--
stdlib array_change_key_case() invalid case defaults to CASE_UPPER (#10880, ext/standard/array.c)
--FILE--
<?php
var_export(array_change_key_case(['FOO' => 1, 'BAR' => 2], 99));
echo "\n";
var_export(array_change_key_case(['FOO' => 1, 'BAR' => 2], 2));
--EXPECT--
array (
  'FOO' => 1,
  'BAR' => 2,
)
array (
  'FOO' => 1,
  'BAR' => 2,
)
