--TEST--
stdlib crc32() JIT
--FILE--
<?php
echo crc32('foo'), "\n";
echo crc32(''), "\n";
--EXPECT--
2356372769
0
