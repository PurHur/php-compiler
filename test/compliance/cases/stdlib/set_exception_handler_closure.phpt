--TEST--
stdlib set_exception_handler() Closure dispatch (#12084, ext/standard/basic_functions.c)
--FILE--
<?php
set_exception_handler(function (Throwable $e) {
    echo "handled\n";
});
throw new Exception('probe');
--EXPECT--
handled
--EXPECT_EXIT--
0
