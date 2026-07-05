--TEST--
stdlib str_getcsv() — null optional delimiter args use Zend defaults JIT (#16492, ext/standard/string.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

var_export(str_getcsv('a,b', null));
echo "\n";
var_export(str_getcsv('a,b', ',', null));
echo "\n";
var_export(str_getcsv('a,b', ',', '"', null));
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 'a',
  1 => 'b',
)
