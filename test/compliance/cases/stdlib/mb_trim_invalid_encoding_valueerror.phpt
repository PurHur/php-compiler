--TEST--
stdlib mb_trim/ltrim/rtrim invalid encoding — ValueError (ext/mbstring/mbstring.c, #23883)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    mb_trim('x', null, 'not-an-encoding');
    echo "mb_trim=OK\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'mb_trim=', $e::class, ':', $e->getMessage(), "\n";
}
try {
    mb_ltrim('x', null, 'not-an-encoding');
    echo "mb_ltrim=OK\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'mb_ltrim=', $e::class, ':', $e->getMessage(), "\n";
}
try {
    mb_rtrim('x', null, 'not-an-encoding');
    echo "mb_rtrim=OK\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'mb_rtrim=', $e::class, ':', $e->getMessage(), "\n";
}
echo mb_trim("\u{3000}ok\u{3000}", null, 'UTF-8'), "\n";
--EXPECT--
mb_trim(): Argument #3 ($encoding) must be a valid encoding, "not-an-encoding" given
mb_ltrim(): Argument #3 ($encoding) must be a valid encoding, "not-an-encoding" given
mb_rtrim(): Argument #3 ($encoding) must be a valid encoding, "not-an-encoding" given
ok
