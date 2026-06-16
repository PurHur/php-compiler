--TEST--
stdlib getrusage() — works with PHP_COMPILER_DISABLE_FFI=1 (pure /proc path, #8970)
--ENV--
PHP_COMPILER_DISABLE_FFI=1
--FILE--
<?php
$u = getrusage();
echo is_array($u) ? "array\n" : "not_array\n";
echo isset($u['ru_utime.tv_sec']) ? "has_utime\n" : "no_utime\n";
echo isset($u['ru_maxrss']) ? "has_maxrss\n" : "no_maxrss\n";
--EXPECT--
array
has_utime
has_maxrss

