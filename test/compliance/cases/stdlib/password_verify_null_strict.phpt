--TEST--
stdlib password_verify() null under strict_types throws TypeError (#17051, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

$hash = password_hash('secret', PASSWORD_BCRYPT);
try {
    password_verify(null, $hash);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_verify(): Argument #1 ($password) must be of type string, null given
