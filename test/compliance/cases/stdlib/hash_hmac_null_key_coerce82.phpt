--TEST--
stdlib hash_hmac() null $key still coerces on 8.2 profile (#20175, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo var_export(hash_hmac('md5', 'd', null), true), "\n";
?>
--EXPECT--
'5f877893cf18d622daed614c1df6f2f9'
