--TEST--
stdlib zlib int params — null coerces to 0 in non-strict mode (#19948)
--FILE--
<?php
echo "gzcompress: " . strlen(gzcompress("hi", null)) . "\n";
echo "zlib_encode: " . strlen(zlib_encode("hi", ZLIB_ENCODING_GZIP, null)) . "\n";
echo "gzdeflate: " . strlen(gzdeflate("hi", null)) . "\n";
echo "gzencode: " . strlen(gzencode("hi", null)) . "\n";
?>
--EXPECT--
gzcompress: 13
zlib_encode: 25
gzdeflate: 7
gzencode: 25
