--TEST--
AOT: intval()
--FILE--
<?php
echo intval(42), "\n";
echo intval(3.9), "\n";
echo intval(true), "\n";
--EXPECT--
42
3
1
