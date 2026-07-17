--TEST--
stdlib hash_hmac() null $key TypeError on 8.4 forward (#20175, ext/hash/hash.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = hash_hmac('md5', 'd', null);
    echo 'uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo var_export(hash_hmac('md5', 'd', ''), true), "\n";
?>
--EXPECT--
hash_hmac(): Argument #3 ($key) must be of type string, null given
'5f877893cf18d622daed614c1df6f2f9'
