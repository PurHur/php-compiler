--TEST--
stdlib hexdec() for hex strings
--FILE--
<?php
echo hexdec('0'), "\n";
echo hexdec('a'), "\n";
echo hexdec('ff'), "\n";
echo hexdec('1000'), "\n";
--EXPECT--
0
10
255
4096
