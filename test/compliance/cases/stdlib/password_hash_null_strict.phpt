--TEST--
stdlib password_hash() null under strict_types throws TypeError (#17050, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

try {
    password_hash(null, PASSWORD_DEFAULT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_hash(): Argument #1 ($password) must be of type string, null given
