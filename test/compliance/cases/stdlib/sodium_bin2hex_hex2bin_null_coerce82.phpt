--TEST--
stdlib sodium_bin2hex()/sodium_hex2bin() null still coerces on 8.2 profile (#20196/#24772, ext/sodium/sodium.c)
--SKIPIF--
<?php if (!extension_loaded('sodium')) { die('skip ext/sodium not loaded on reference host'); } ?>
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
set_error_handler(static function (int $n, string $m): bool {
    return E_DEPRECATED === $n;
});
echo var_export(sodium_bin2hex(null), true), "\n";
echo var_export(sodium_hex2bin(null), true), "\n";
echo var_export(sodium_hex2bin('61', null), true), "\n";
?>
--EXPECT--
''
''
'a'
