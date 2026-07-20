--TEST--
crypt() null salt/string soft-null; array TypeError (#21280, reverts #18657 TypeError-on-null)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    $r = crypt('password', null);
    echo is_string($r) ? "salt-null OK\n" : "FAIL\n";
} catch (TypeError $e) {
    echo $e->getMessage() . "\n";
}
try {
    $r = crypt(null, '$2y$10$abcdefghijklmnopqrstuv');
    echo is_string($r) ? "string-null OK\n" : "FAIL\n";
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
salt-null OK
string-null OK
crypt(): Argument #1 ($string) must be of type string, array given
