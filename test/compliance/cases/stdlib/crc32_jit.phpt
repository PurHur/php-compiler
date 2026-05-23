--TEST--
stdlib crc32() JIT
--FILE--
<?php
echo crc32('test'), "\n";
--EXPECT--
3632233996
