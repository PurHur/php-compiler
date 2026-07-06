--TEST--
stdlib zend_thread_id() — function_exists on PHP_COMPILER_PROFILE=8.4 (#16851, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('zend_thread_id')) {
    echo "fail: function_exists false\n";
    exit(1);
}
$id = zend_thread_id();
echo is_int($id) && $id > 0 ? "ok\n" : "bad\n";
--EXPECT--
ok
