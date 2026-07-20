--TEST--
AOT: hash_hmac/pack null value operands on 8.4 (#21209)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo hash_hmac('md5', null, 'k'), "\n";
echo pack('a*', null) === '' ? "pack_ok\n" : "pack_bad\n";
?>
--EXPECT--
cd32bedd46aa63cffa3023f050fc78e3
pack_ok
