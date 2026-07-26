--TEST--
get_debug_type/count/is_* named value argument (JIT, issue #23263)
--FILE--
<?php
echo get_debug_type(value: 1), PHP_EOL;
echo count(value: [1, 2]), PHP_EOL;
var_export(is_string(value: 'a'));
echo PHP_EOL;
--EXPECT--
int
2
true
