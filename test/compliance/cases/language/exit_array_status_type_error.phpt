--TEST--
Language: exit()/die() array status TypeError on PHP 8.4 function form (#22492, zend_builtin_functions.c)
--FILE--
<?php
try {
    $e = 'exit';
    $e([1, 2]);
    echo "SURVIVED\n";
} catch (TypeError $ex) {
    echo 'TypeError:', $ex->getMessage(), "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
try {
    die(new stdClass());
    echo "SURVIVED2\n";
} catch (TypeError $ex) {
    echo 'TypeError:', $ex->getMessage(), "\n";
} catch (Throwable $ex) {
    echo get_class($ex), ':', $ex->getMessage(), "\n";
}
echo "ok\n";
--EXPECT--
TypeError:exit(): Argument #1 ($status) must be of type string|int, array given
TypeError:exit(): Argument #1 ($status) must be of type string|int, stdClass given
ok
