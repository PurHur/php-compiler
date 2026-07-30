--TEST--
stdlib gmp_init() — 0x/0b/0o prefix auto-detect when base omitted/0 (#25405, ext/gmp/gmp.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
if (!function_exists('gmp_init')) {
    echo "missing\n";
    exit(1);
}
echo gmp_strval(gmp_init('0x10')), "\n";
echo gmp_strval(gmp_init('0X10')), "\n";
echo gmp_strval(gmp_init('0b1010')), "\n";
echo gmp_strval(gmp_init('0o17')), "\n";
echo gmp_strval(gmp_init('0x10', 0)), "\n";
echo gmp_strval(gmp_init('10', 16)), "\n";
try {
    gmp_init('0x10', 10);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo "value_error\n";
}
echo "ok\n";
--EXPECT--
16
16
10
15
16
16
value_error
ok
