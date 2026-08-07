--TEST--
HashContext ReflectionClass::isFinal() (php-src ext/hash/hash.stub.php; #28384)
--FILE--
<?php
hash_init('sha256');
echo (new ReflectionClass(HashContext::class))->isFinal() ? "hash_final_yes\n" : "hash_final_no\n";
?>
--EXPECT--
hash_final_yes
