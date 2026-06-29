--TEST--
stdlib explode() negative limit (#13424, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

var_export(explode('a', 'bab', -1));
echo "\n";
var_export(explode('-', 'a-b-c-d', -2));
echo "\n";
--EXPECT--
array (
  0 => 'b',
)
array (
  0 => 'a',
  1 => 'b',
)
