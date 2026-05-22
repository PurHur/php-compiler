--TEST--
stdlib strcspn()
--FILE--
<?php
echo strcspn('abc123', '123'), "\n";
echo strcspn('123abc', '123'), "\n";
echo strcspn('a', 'ab'), "\n";
--EXPECT--
3
0
1
