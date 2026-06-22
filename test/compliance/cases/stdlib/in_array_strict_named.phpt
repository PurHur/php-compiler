--TEST--
stdlib in_array()/array_search() — strict: named parameter (#10321, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

var_export(in_array(1, [1, 2, 3], strict: true));
echo "\n";
var_export(array_search(2, ['a' => 1, 'b' => 2], strict: true));
echo "\n";
--EXPECT--
true
'b'
