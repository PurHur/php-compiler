--TEST--
stdlib sodium_bin2hex/hex2bin(null) soft-null on 8.4 (#24772, reverts #20196 TypeError half)
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
$deps = [];
try {
    $r = sodium_hex2bin(null);
    echo 'hex2bin OK ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo 'hex2bin_dep=', (isset($deps[0]) && false !== strpos($deps[0], 'sodium_hex2bin(): Passing null to parameter #1 ($string)')) ? '1' : '0', "\n";
$deps = [];
try {
    $r = sodium_hex2bin('61', null);
    echo 'ignore OK ', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
echo 'ignore_dep=', (isset($deps[0]) && false !== strpos($deps[0], 'sodium_hex2bin(): Passing null to parameter #2 ($ignore)')) ? '1' : '0', "\n";
echo var_export(sodium_bin2hex(''), true), "\n";
echo var_export(sodium_hex2bin(''), true), "\n";
?>
--EXPECT--
bin2hex OK ''
bin2hex_dep=1
hex2bin OK ''
hex2bin_dep=1
ignore OK 'a'
ignore_dep=1
''
''
