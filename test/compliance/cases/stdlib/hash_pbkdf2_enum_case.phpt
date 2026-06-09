--TEST--
stdlib hash_pbkdf2() — enum case operands TypeError (#5972, ext/hash/hash_pbkdf2.c)
--FILE--
<?php
declare(strict_types=1);

enum E: string { case A = 'secret'; }
enum Algo: string { case Sha = 'sha256'; }
enum Salt: string { case S = 'salt'; }

try {
    hash_pbkdf2('sha256', E::A, 'salt', 1000);
    echo "password: uncaught\n";
} catch (TypeError $e) {
    echo 'password: ', $e->getMessage(), "\n";
}

try {
    hash_pbkdf2(Algo::Sha, 'password', 'salt', 1000);
    echo "algo: uncaught\n";
} catch (TypeError $e) {
    echo 'algo: ', $e->getMessage(), "\n";
}

try {
    hash_pbkdf2('sha256', 'password', Salt::S, 1000);
    echo "salt: uncaught\n";
} catch (TypeError $e) {
    echo 'salt: ', $e->getMessage(), "\n";
}
?>
--EXPECT--
password: hash_pbkdf2(): Argument #2 ($password) must be of type string, E given
algo: hash_pbkdf2(): Argument #1 ($algo) must be of type string, Algo given
salt: hash_pbkdf2(): Argument #3 ($salt) must be of type string, Salt given
