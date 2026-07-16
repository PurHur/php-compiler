--TEST--
zlib gzencode() gzip OS byte is Unix 0x03 (not 0xff); XFL matches zlib (#19516, ext/zlib/zlib.c)
--FILE--
<?php
declare(strict_types=1);
$z1 = gzencode('hello', 1);
echo 'os=', bin2hex($z1[9]), "\n";
echo 'hdr1=', bin2hex(substr($z1, 0, 10)), "\n";
$z9 = gzencode('hello', 9);
echo 'hdr9=', bin2hex(substr($z9, 0, 10)), "\n";
echo gzdecode($z1) === 'hello' ? "roundtrip\n" : "roundtrip-fail\n";
?>
--EXPECT--
os=03
hdr1=1f8b0800000000000403
hdr9=1f8b0800000000000203
roundtrip
