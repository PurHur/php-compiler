--TEST--
stdlib array_map() string builtin callback — null haystack element coerced (#16116, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_map('strlen', [null]));
echo "\n";
var_export(array_map('strtoupper', [null]));
echo "\n";
var_export(array_map('trim', [null]));
--EXPECT--
array (
  0 => 0,
)
array (
  0 => '',
)
array (
  0 => '',
)
