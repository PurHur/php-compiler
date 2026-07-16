--TEST--
stdlib password_needs_rehash(null) — TypeError on 8.4 forward profile JIT (#18655, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    password_needs_rehash(null, PASSWORD_DEFAULT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
password_needs_rehash(): Argument #1 ($hash) must be of type string, null given
