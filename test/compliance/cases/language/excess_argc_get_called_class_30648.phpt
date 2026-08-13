--TEST--
language: get_called_class() excess argc → ArgumentCountError (#30648, Zend/zend_builtin_functions.c)
--FILE--
<?php
class GccExcessArgcHost {
    public static function probe(): void {
        try {
            get_called_class(1);
            echo "NO_THROW\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        try {
            get_called_class(1, 2);
            echo "NO_THROW2\n";
        } catch (Throwable $e) {
            echo get_class($e), ': ', $e->getMessage(), "\n";
        }
        echo 'ok=', get_called_class(), "\n";
    }
}
GccExcessArgcHost::probe();
--EXPECT--
ArgumentCountError: get_called_class() expects exactly 0 arguments, 1 given
ArgumentCountError: get_called_class() expects exactly 0 arguments, 2 given
ok=GccExcessArgcHost
