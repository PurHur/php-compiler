--TEST--
stdlib password_needs_rehash() — ArgumentCountError when too few args (#9263, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

try {
    password_needs_rehash('hash-only');
    echo "uncaught\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
password_needs_rehash() expects at least 2 arguments, 1 given
