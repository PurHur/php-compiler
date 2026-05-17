--TEST--
stdlib str_repeat with multiplier zero
--FILE--
<?php
$s = str_repeat('x', 0);
echo strlen($s), "\n";
echo strcmp($s, ''), "\n";
--EXPECT--
0
0
