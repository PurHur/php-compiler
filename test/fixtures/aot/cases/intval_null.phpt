--TEST--
AOT: intval() strings and null
--FILE--
<?php
echo intval('9'), "\n";
echo intval(null), "\n";
--EXPECT--
9
0
