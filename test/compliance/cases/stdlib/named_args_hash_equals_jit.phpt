--TEST--
hash_equals known_string/user_string named args (JIT, issue #23205)
--FILE--
<?php
var_export(hash_equals(known_string: 'aa', user_string: 'aa'));
echo PHP_EOL;
var_export(hash_equals(known_string: 'aa', user_string: 'bb'));
echo PHP_EOL;
--EXPECT--
true
false
