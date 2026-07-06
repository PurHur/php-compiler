--TEST--
stdlib spl_autoload_register() — int callback TypeError (#16692, ext/spl/php_spl.c)
--FILE--
<?php
declare(strict_types=1);

try {
    spl_autoload_register(1);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
spl_autoload_register(): Argument #1 ($callback) must be a valid callback or null, no array or string given
