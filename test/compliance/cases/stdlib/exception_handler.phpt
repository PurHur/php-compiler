--TEST--
Runtime: set_exception_handler() handles uncaught Throwable (issue #3146)
--FILE--
<?php
set_exception_handler(function (Throwable $e): void {
    echo 'handled:', $e->getMessage(), "\n";
});
throw new Exception('x');
--EXPECT--
handled:x
--EXPECT_EXIT--
0
