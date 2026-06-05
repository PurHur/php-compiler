--TEST--
stdlib password_needs_rehash() — backed enum case TypeError (#6242, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'secret';
}

try {
    password_needs_rehash(E::A, PASSWORD_BCRYPT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_needs_rehash(): Argument #1 ($hash) must be of type string, E given
