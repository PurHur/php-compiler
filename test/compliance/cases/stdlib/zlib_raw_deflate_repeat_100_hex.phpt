--TEST--
stdlib gzdeflate raw bytes — str_repeat('a',100) libz hex parity (#14251, ext/zlib/zlib.c)
--FILE--
<?php
$plain = str_repeat('a', 100);
$raw = gzdeflate($plain);
echo strlen($raw), "\n";
echo bin2hex($raw), "\n";
echo $plain === gzinflate($raw) ? "roundtrip-ok\n" : "roundtrip-fail\n";
?>
--EXPECT--
6
4b4ca43d0000
roundtrip-ok
