--TEST--
stdlib intval() for booleans
--FILE--
<?php
echo intval(true), "\n";
echo intval(false), "\n";
--EXPECT--
1
0
