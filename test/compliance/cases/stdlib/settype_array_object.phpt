--TEST--
stdlib settype($list, 'object') — array becomes stdClass (#12714, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);
$list = [1, 2, 3];
settype($list, 'object');
$vars = get_object_vars($list);
var_export([get_class($list), $vars]);
echo "\n";
--EXPECT--
array (
  0 => 'stdClass',
  1 => 
  array (
    0 => 1,
    1 => 2,
    2 => 3,
  ),
)
