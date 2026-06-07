--TEST--
stdlib gzcompress/gzuncompress via VmZlibNative FFI without host ext-zlib (#6356)
--FILE--
<?php
$plain = 'hello bootstrap';
$z = gzcompress($plain);
echo is_string($z) ? '1' : '0';
echo gzuncompress($z) === $plain ? '1' : '0';
echo "\n";
--EXPECT--
11
