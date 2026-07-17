--TEST--
crypt() null salt/password — TypeError (#18657, ext/standard/crypt.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    crypt('password', null);
    echo "FAIL\n";
} catch (TypeError $e) {
    echo $e->getMessage() . "\n";
}
try {
    crypt(null, '$2y$10$abcdefghijklmnopqrstuv');
    echo "FAIL\n";
} catch (TypeError $e) {
    echo $e->getMessage() . "\n";
}
?>
--EXPECT--
crypt(): Argument #2 ($salt) must be of type string, null given
crypt(): Argument #1 ($password) must be of type string, null given
