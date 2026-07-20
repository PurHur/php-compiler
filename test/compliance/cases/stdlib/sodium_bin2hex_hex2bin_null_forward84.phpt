--TEST--
stdlib sodium_bin2hex(null) soft-null on 8.4; hex2bin still TypeError (#21517, reverts #20196)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$deps = [];
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        $deps[] = $m;
    }

    return true;
});
try {
    $r = sodium_bin2hex(null);
    echo 'bin2hex OK ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo 'bin2hex_dep=', (isset($deps[0]) && false !== strpos($deps[0], 'sodium_bin2hex(): Passing null to parameter #1 ($string)')) ? '1' : '0', "\n";
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
bin2hex OK ''
bin2hex_dep=1
sodium_hex2bin(): Argument #1 ($string) must be of type string, null given
sodium_hex2bin(): Argument #2 ($ignore) must be of type string, null given
''
''
