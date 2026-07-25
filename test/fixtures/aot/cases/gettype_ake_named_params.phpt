--TEST--
AOT: gettype/array_key_exists named arguments (#23193)
--FILE--
<?php
echo gettype(value: []), "\n";
echo array_key_exists(key: 'a', array: ['a' => 1]) ? "1\n" : "0\n";
--EXPECT--
array
1
