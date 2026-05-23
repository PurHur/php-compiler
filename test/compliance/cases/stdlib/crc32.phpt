--TEST--
stdlib crc32()
--FILE--
<?php
echo crc32(''), "\n";
echo crc32('test'), "\n";
echo crc32("The quick brown fox jumped over the lazy dog."), "\n";
echo crc32('a' . chr(0) . 'b'), "\n";
--EXPECT--
0
3632233996
2191738434
367556721
