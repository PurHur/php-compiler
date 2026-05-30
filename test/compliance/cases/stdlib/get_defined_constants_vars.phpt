--TEST--
stdlib get_defined_constants() and get_defined_vars() (issue #3135)
--FILE--
<?php
define('MY_FLAG', 42);
$local = 'here';
$constants = get_defined_constants(true);
$vars = get_defined_vars();
echo array_key_exists('user', $constants) ? (isset($constants['user']['MY_FLAG']) ? 'const_ok' : 'const_miss') : 'no_user';
echo "\n";
echo array_key_exists('local', $vars) && $vars['local'] === 'here' ? "vars_ok\n" : "vars_bad\n";
--EXPECT--
const_ok
vars_ok
