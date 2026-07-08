--TEST--
AOT: get_defined_constants(category:) on PHP_COMPILER_PROFILE=8.4 (#17436, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$core = get_defined_constants(category: 'Core');
echo array_key_exists('PHP_VERSION', $core) ? "ok\n" : "fail\n";
--EXPECT--
ok
