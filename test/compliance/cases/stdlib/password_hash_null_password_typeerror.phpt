--TEST--
stdlib password_hash() — null $password TypeError (#16103, ext/standard/password.c Z_PARAM_STR)
--FILE--
<?php
try {
    password_hash(null, PASSWORD_BCRYPT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_hash(): Argument #1 ($password) must be of type string, null given
