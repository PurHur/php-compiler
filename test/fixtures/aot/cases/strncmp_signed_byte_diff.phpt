--TEST--
AOT: strncmp()/strcmp() signed byte difference (#4345)
--FILE--
<?php
echo strncmp('a', 'A', 1), "\n";
echo strcmp('a', '1'), "\n";
--EXPECT--
32
48
