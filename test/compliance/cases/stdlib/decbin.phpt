--TEST--
stdlib decbin() for non-negative integers
--FILE--
<?php
echo decbin(0), "\n";
echo decbin(10), "\n";
echo decbin(255), "\n";
echo decbin(8), "\n";
--EXPECT--
0
1010
11111111
1000
