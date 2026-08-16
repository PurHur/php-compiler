--TEST--
stdlib password_hash/password_needs_rehash null $options TypeError (#31421, ext/standard/password.c)
--FILE--
<?php
try {
    password_hash('x', PASSWORD_BCRYPT, null);
    echo "hash_fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    password_needs_rehash('x', PASSWORD_DEFAULT, null);
    echo "rehash_fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$h = password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]);
echo is_string($h) && strlen($h) > 20 ? "hash_ok\n" : "hash_bad\n";
$r = password_needs_rehash($h, PASSWORD_BCRYPT, ['cost' => 10]);
echo is_bool($r) ? "rehash_ok\n" : "rehash_bad\n";
--EXPECT--
password_hash(): Argument #3 ($options) must be of type array, null given
password_needs_rehash(): Argument #3 ($options) must be of type array, null given
hash_ok
rehash_ok
