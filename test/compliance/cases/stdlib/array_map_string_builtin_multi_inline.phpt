--TEST--
stdlib array_map() — string builtin over multiple inline arrays (#16085, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$result = array_map('explode', [','], ['a,b']);
var_export($result);
echo "\n";
--EXPECT--
array (
  0 => array (
    0 => 'a',
    1 => 'b',
  ),
)
