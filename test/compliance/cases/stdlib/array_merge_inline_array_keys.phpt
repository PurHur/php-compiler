--TEST--
stdlib array_merge() inline array_keys() producer slot (#12450, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$src = ['a' => 1, 'b' => 2];
var_export(array_merge(array_keys($src), ['b']));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
  2 => 'b',
)
