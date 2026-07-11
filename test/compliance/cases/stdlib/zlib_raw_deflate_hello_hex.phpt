--TEST--
stdlib zlib_encode RAW / gzdeflate canonical hello — libz hex parity (#12706, ext/zlib/zlib.c)
--FILE--
<?php
$plain = 'hello';
$raw = gzdeflate($plain);
$enc = zlib_encode($plain, ZLIB_ENCODING_RAW);
echo bin2hex($raw), "\n";
echo bin2hex($enc), "\n";
echo $plain === gzinflate($raw) ? "roundtrip-ok\n" : "roundtrip-fail\n";
?>
--EXPECT--
cb48cdc9c90700
cb48cdc9c90700
roundtrip-ok
