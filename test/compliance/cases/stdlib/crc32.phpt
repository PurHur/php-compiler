--TEST--
stdlib crc32()
--FILE--
<?php
echo crc32('test'), "\n";
echo crc32(''), "\n";
echo crc32('abc'), "\n";
echo crc32('foo'), "\n";
echo crc32("\x00\xff"), "\n";
echo crc32('The quick brown fox jumped over the lazy dog.'), "\n";
--EXPECT--
3632233996
0
891568578
2356372769
1826356594
2191738434
