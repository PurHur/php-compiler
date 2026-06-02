--TEST--
AOT: intval() optional $base (issue #4174)
--FILE--
<?php
echo intval('ff', 16), "\n";
echo intval('1010', 2), "\n";
echo intval(42, 16), "\n";
--EXPECT--
255
10
42
