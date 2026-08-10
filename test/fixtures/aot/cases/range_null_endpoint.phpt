--TEST--
AOT range() null $start/$end coerce to 0 (#29348)
--ENV--
PHP_COMPILER_PROFILE=8.4 forward
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo implode(',', range(null, 3)), "\n";
echo implode(',', range(0, null)), "\n";
--EXPECT--
0,1,2,3
0
