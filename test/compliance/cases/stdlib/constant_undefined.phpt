--TEST--
stdlib constant() — undefined constant throws Error (issue #3813, PHP 8.2)
--FILE--
<?php
try {
    constant('NO_SUCH_CONST');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
Error
Undefined constant "NO_SUCH_CONST"
