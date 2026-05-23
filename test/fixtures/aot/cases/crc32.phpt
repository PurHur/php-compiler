--TEST--
AOT crc32()
--FILE--
<?php
echo crc32('test'), "\n";
echo crc32(''), "\n";
--EXPECT--
3632233996
0
