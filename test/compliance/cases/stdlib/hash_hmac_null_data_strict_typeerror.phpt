--TEST--
stdlib hash_hmac() null $data under strict_types — TypeError (#16101, ext/hash/hash.c)
--FILE--
<?php
declare(strict_types=1);
try {
    hash_hmac('md5', null, 'key');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
hash_hmac(): Argument #2 ($data) must be of type string, null given
