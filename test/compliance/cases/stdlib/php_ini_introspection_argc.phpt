--TEST--
stdlib php_ini_loaded_file() — ArgumentCountError when extra arguments (#6117)
--FILE--
<?php
try {
    php_ini_loaded_file(1);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError
php_ini_loaded_file() expects exactly 0 arguments, 1 given
