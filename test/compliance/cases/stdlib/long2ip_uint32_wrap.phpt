--TEST--
stdlib long2ip() uint32 wrap for negative and overflow inputs (issue #9297)
--FILE--
<?php
echo long2ip(-1), "\n";
echo long2ip(4294967296), "\n";
echo long2ip(2130706433), "\n";
--EXPECT--
255.255.255.255
0.0.0.0
127.0.0.1
