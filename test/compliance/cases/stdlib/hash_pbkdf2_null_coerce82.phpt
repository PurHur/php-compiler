--TEST--
stdlib hash_pbkdf2() null password still coerces on 8.2 profile (#20659, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo var_export(hash_pbkdf2('sha256', null, 'salt', 1) !== '', true), "\n";
echo var_export(hash_pbkdf2('sha256', 'p', null, 1) !== '', true), "\n";
?>
--EXPECT--
true
true
