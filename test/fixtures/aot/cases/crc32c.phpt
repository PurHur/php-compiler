--TEST--
AOT: crc32c() subset
--FILE--
<?php
echo crc32c('test'), "\n";
echo crc32c(''), "\n";
echo crc32c('abc'), "\n";
echo crc32c('123456789'), "\n";
--EXPECT--
2258662080
0
910901175
3808858755
--EXPECT_EXIT--
0
