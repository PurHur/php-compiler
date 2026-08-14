--TEST--
stdlib mb_str_pad() — not advertised or callable on PHP_COMPILER_PROFILE=8.2 (#16776, #31174)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('mb_str_pad') ? "exists_fail\n" : "exists_ok\n";
echo is_callable('mb_str_pad') ? "callable_fail\n" : "callable_ok\n";
try {
    echo mb_str_pad('a', 3), "\n";
    echo "call_fail\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
exists_ok
callable_ok
Error: Call to undefined function mb_str_pad()
