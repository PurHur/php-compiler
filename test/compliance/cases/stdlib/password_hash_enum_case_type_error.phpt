--TEST--
stdlib password_hash() — backed enum case TypeError (#5904, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

enum S: string {
    case A = 'secret';
}

try {
    password_hash(S::A, PASSWORD_DEFAULT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_hash(): Argument #1 ($password) must be of type string, S given
