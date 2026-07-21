--TEST--
AOT: hash_hmac(null $key)/hash_update(null) soft-null on 8.4 (#21557)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo hash_hmac('md5', 'd', null), "\n";
$h = hash_init('md5');
hash_update($h, null);
echo hash_final($h), "\n";
?>
--EXPECT--
5f877893cf18d622daed614c1df6f2f9
d41d8cd98f00b204e9800998ecf8427e
