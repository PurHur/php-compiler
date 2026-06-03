--TEST--
ord(): empty string returns 0 (#4331)
--FILE--
<?php
echo ord(''), "\n";
--EXPECT--
0
