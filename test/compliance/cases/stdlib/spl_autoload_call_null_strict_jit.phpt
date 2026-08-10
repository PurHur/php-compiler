--TEST--
stdlib spl_autoload_call(null) JIT TypeError under strict_types (#29820, ext/spl/php_spl.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
try {
    spl_autoload_call(null);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
TypeError
spl_autoload_call(): Argument #1 ($class) must be of type string, null given
