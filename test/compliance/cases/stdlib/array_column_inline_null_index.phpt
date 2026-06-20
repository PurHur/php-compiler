--TEST--
stdlib array_column() inline array literal + null index_key (#9305)
--FILE--
<?php
declare(strict_types=1);
var_export(array_column([['name' => 'a'], ['name' => 'b']], 'name', null));
echo "\n";
var_export(array_search(null, [1, 2, 3], true));
echo "\n";
var_export(in_array(null, [1, 2, 3], true));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
false
false
