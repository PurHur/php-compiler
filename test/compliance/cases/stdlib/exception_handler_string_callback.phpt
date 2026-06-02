--TEST--
Runtime: set_exception_handler() with string function name (issue #3146)
--FILE--
<?php
function my_handler(Throwable $e): void {
    echo 'str:', $e->getMessage(), "\n";
}
set_exception_handler('my_handler');
throw new Exception('y');
--EXPECT--
str:y
--EXPECT_EXIT--
0
