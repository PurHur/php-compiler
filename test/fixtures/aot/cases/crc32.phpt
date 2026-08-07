--TEST--
AOT: crc32() subset
--FILE--
<?php
echo crc32('test'), "\n";
echo crc32(''), "\n";
echo crc32('foo'), "\n";
--EXPECT--
3632233996
0
2356372769
--EXPECT_EXIT--
0
