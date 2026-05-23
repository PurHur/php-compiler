--TEST--
AOT: crc32() subset
--FILE--
<?php
echo crc32(''), "\n";
echo crc32('foo'), "\n";
echo crc32('oo', crc32('f')), "\n";
--EXPECT--
0
2356372769
2356372769
--EXPECT_EXIT--
0
