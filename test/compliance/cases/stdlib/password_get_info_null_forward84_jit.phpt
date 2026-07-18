--TEST--
stdlib password_get_info(null) — TypeError on 8.4 forward profile JIT (#20672, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
try {
    password_get_info(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
password_get_info(): Argument #1 ($hash) must be of type string, null given
