--TEST--
stdlib get_defined_constants() category named param on forward 8.4 profile (#17436, ext/standard/basic_functions.c)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$core = get_defined_constants(category: 'Core');
echo array_key_exists('PHP_VERSION', $core) ? "core_ok\n" : "core_missing\n";
$user = get_defined_constants(category: 'user');
echo is_array($user) ? "user_ok\n" : "user_bad\n";
--EXPECT--
core_ok
user_ok
