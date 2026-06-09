--TEST--
stdlib hash_pbkdf2() — enum case operands TypeError JIT (#5972, ext/hash/hash_pbkdf2.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'secret'; }

try {
    hash_pbkdf2('sha256', E::A, 'salt', 1000);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_pbkdf2(): Argument #2 ($password) must be of type string, E given
