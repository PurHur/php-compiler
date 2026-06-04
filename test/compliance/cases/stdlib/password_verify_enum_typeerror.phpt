--TEST--
stdlib password_verify() — backed enum case TypeError (#5821, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'secret';
}

try {
    password_verify(E::A, '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_verify(): Argument #1 ($password) must be of type string, E given
