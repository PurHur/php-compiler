--TEST--
stdlib crc32() JIT
--FILE--
<?php
echo crc32('foo'), "\n";
echo crc32('oo', crc32('f')), "\n";
--EXPECT--
2356372769
2356372769
