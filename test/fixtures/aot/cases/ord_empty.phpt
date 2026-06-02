--TEST--
AOT: ord() empty string returns 0 (#4331)
--FILE--
<?php
echo ord(''), "\n";
echo ord('A'), "\n";
--EXPECT--
0
65
