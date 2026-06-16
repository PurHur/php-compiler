--TEST--
stdlib random_bytes() via VmRandomPure when libc FFI disabled (#8921)
--ENV--
PHP_COMPILER_DISABLE_FFI=1
--FILE--
<?php
$bytes = random_bytes(8);
echo strlen($bytes) === 8 ? "ok\n" : "fail\n";
try {
    random_bytes(0);
    echo "no_value_error\n";
} catch (ValueError $e) {
    echo "value_error\n";
}
--EXPECT--
ok
value_error
