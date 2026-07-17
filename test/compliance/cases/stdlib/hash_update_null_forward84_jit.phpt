--TEST--
stdlib hash_update() null $data TypeError on 8.4 forward JIT (#20195, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
$c = hash_init('sha1');
try {
    hash_update($c, null);
    echo 'uncaught ', hash_final($c), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
$c2 = hash_init('sha1');
hash_update($c2, '');
echo hash_final($c2), "\n";
?>
--EXPECT--
hash_update(): Argument #2 ($data) must be of type string, null given
da39a3ee5e6b4b0d3255bfef95601890afd80709
