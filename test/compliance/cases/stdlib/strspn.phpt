--TEST--
stdlib strspn()
--FILE--
<?php
echo strspn('abc123', 'abc'), "\n";
echo strspn('123abc', 'abc'), "\n";
echo strspn('a', 'ab'), "\n";
--EXPECT--
3
0
1
