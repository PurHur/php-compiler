--TEST--
stdlib sodium_bin2hex()/sodium_hex2bin()/sodium_memzero() (#3438)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
if (!extension_loaded('sodium')
    || !function_exists('sodium_bin2hex')
    || !function_exists('sodium_hex2bin')
    || !function_exists('sodium_memzero')) {
    echo "missing\n";
    exit(0);
}
$hex = sodium_bin2hex('hello');
echo $hex === '68656c6c6f' ? "bin2hex_ok\n" : "bin2hex_fail\n";
echo sodium_hex2bin($hex) === 'hello' ? "hex2bin_ok\n" : "hex2bin_fail\n";
echo sodium_hex2bin('61 62', ' ') === 'ab' ? "ignore_ok\n" : "ignore_fail\n";
try {
    sodium_hex2bin('zz');
    echo "hex_fail\n";
} catch (\SodiumException $e) {
    echo "hex_err_ok\n";
}
$secret = 'password';
sodium_memzero($secret);
echo null === $secret ? "memzero_ok\n" : "memzero_fail\n";
try {
    $n = 1;
    sodium_memzero($n);
    echo "type_fail\n";
} catch (\SodiumException $e) {
    echo "type_ok\n";
}
--EXPECT--
bin2hex_ok
hex2bin_ok
ignore_ok
hex_err_ok
memzero_ok
type_ok
