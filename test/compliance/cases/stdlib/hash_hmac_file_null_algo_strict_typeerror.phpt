--TEST--
stdlib hash_hmac_file() null $algo under strict_types — TypeError (#29890, ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);
try {
    hash_hmac_file(null, '/etc/hosts', 'k');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_hmac_file(): Argument #1 ($algo) must be of type string, null given
