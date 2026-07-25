--TEST--
basename/uniqid/gettype/array_key_exists named arguments (JIT, issue #23193)
--FILE--
<?php
echo basename(path: '/a/b.txt', suffix: '.txt'), PHP_EOL;
var_export(gettype(value: []));
echo PHP_EOL;
var_export(array_key_exists(key: 'a', array: ['a' => 1]));
echo PHP_EOL;
--EXPECT--
b
'array'
true
