--TEST--
stdlib sodium_bin2hex()/sodium_hex2bin() null TypeError on 8.4 forward (#20196, ext/sodium/sodium.c)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    $r = sodium_bin2hex(null);
    echo 'bin2hex uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = sodium_hex2bin(null);
    echo 'hex2bin uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    $r = sodium_hex2bin('61', null);
    echo 'ignore uncaught ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo var_export(sodium_bin2hex(''), true), "\n";
echo var_export(sodium_hex2bin(''), true), "\n";
?>
--EXPECT--
sodium_bin2hex(): Argument #1 ($string) must be of type string, null given
sodium_hex2bin(): Argument #1 ($string) must be of type string, null given
sodium_hex2bin(): Argument #2 ($ignore) must be of type string, null given
''
''
