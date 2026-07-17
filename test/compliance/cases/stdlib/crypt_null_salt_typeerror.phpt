--TEST--
crypt() null salt/string — TypeError (#18657, #3389, ext/standard/crypt.c)
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
try {
    crypt([], 'xx');
    echo "FAIL\n";
} catch (TypeError $e) {
    echo $e->getMessage() . "\n";
}
?>
--EXPECT--
crypt(): Argument #2 ($salt) must be of type string, null given
crypt(): Argument #1 ($string) must be of type string, null given
crypt(): Argument #1 ($string) must be of type string, array given
