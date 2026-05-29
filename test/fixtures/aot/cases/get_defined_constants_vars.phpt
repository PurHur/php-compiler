--TEST--
AOT: get_defined_constants() categorized user constants (issue #3135)
--FILE--
<?php
define('MY_FLAG', 42);
$constants = get_defined_constants(true);
echo array_key_exists('user', $constants) ? (isset($constants['user']['MY_FLAG']) ? 'const_ok' : 'const_miss') : 'no_user';
echo "\n";
--EXPECT--
const_ok
