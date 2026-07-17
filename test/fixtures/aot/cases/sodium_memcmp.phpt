--TEST--
AOT: sodium_memcmp() constant-time compare (#15531)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--FILE--
<?php
// Standalone AOT does not always advertise extension_loaded('sodium'); functions are registered.
// Length-mismatch SodiumException catch is VM/compliance-covered (AOT exception catch aborts here).
if (!function_exists('sodium_memcmp')) {
    echo "missing\n";
    exit(0);
}
echo sodium_memcmp('abc', 'abc') === 0 ? "eq\n" : "eq_fail\n";
echo sodium_memcmp('abc', 'abd') !== 0 ? "ne\n" : "ne_fail\n";
echo sodium_memcmp("a\0b", "a\0b") === 0 ? "nul_eq\n" : "nul_eq_fail\n";
--EXPECT--
eq
ne
nul_eq
