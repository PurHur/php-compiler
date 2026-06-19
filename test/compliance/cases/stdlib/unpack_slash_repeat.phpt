--TEST--
stdlib unpack() slash-separated repeat formats (#9652, ext/standard/pack.c)
--FILE--
<?php
declare(strict_types=1);

var_export(unpack('C2/C', 'abc'));
echo "\n";
var_export(unpack('H2/H', 'abcd'));
echo "\n";
--EXPECT--
array (
  1 => 99,
  2 => 98,
)
array (
  1 => '6',
)
