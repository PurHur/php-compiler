--TEST--
stdlib hash_update() null $data still coerces on 8.2 profile (#20195, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
$c = hash_init('sha1');
hash_update($c, null);
echo hash_final($c), "\n";
?>
--EXPECT--
da39a3ee5e6b4b0d3255bfef95601890afd80709
