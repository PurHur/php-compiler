--TEST--
stdlib in_array() inline array_keys() haystack — nested builtin materializes (#12423, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$src = ['a' => 1, 'b' => 2];

var_export(in_array('a', array_keys($src), true));
echo "\n";

var_export(in_array('string.rot13', stream_get_filters(), true));
echo "\n";

$k = array_keys($src);
var_export(in_array('a', $k, true));
echo "\n";
--EXPECT--
true
true
true
