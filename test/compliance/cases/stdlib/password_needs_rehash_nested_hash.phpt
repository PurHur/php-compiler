--TEST--
stdlib password_needs_rehash() — float $algo TypeError not internal TYPE_DOUBLE fatal (#17708, ext/standard/password.c)
--FILE--
<?php
declare(strict_types=1);

$h = password_hash('x', PASSWORD_BCRYPT);
try {
    password_needs_rehash($h, 3.14);
    echo "no_error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
password_needs_rehash(): Argument #2 ($algo) must be of type string|int, float given
