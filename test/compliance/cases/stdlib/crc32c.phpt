--TEST--
stdlib crc32c()
--FILE--
<?php
echo crc32c('test'), "\n";
echo crc32c(''), "\n";
echo crc32c('abc'), "\n";
echo crc32c('123456789'), "\n";
echo crc32c('foo'), "\n";
echo crc32c("\x00\xff"), "\n";
echo crc32c('The quick brown fox jumped over the lazy dog.'), "\n";
--EXPECT--
2258662080
0
910901175
3808858755
3485773341
1545348227
535183965
