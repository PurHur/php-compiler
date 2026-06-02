--TEST--
stdlib strspn()/strcspn() empty $characters mask (#4119)
--FILE--
<?php
echo strspn('abc', ''), "\n";
echo strcspn('abc', ''), "\n";
echo strspn('abc', '', 1, 1), "\n";
echo strcspn('abc', '', 1, 1), "\n";
--EXPECT--
0
3
0
1
