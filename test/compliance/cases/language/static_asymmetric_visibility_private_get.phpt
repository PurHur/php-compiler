--TEST--
PHP 8.4 static asymmetric visibility: private(get) read guard (#8751, zend_property_hooks.c)
--FILE--
<?php
class Vault {
    private(get) static string $secret = 'hidden';

    public static function readInside(): void {
        echo self::$secret, "\n";
    }
}

try {
    echo Vault::$secret, "\n";
    echo "uncaught\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
Vault::readInside();
Vault::$secret = 'ok';
try {
    echo Vault::$secret, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot access private(get) property Vault::$secret from global scope
hidden
Error: Cannot access private(get) property Vault::$secret from global scope
