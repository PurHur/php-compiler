--TEST--
AOT: sprintf() extended conversions (#4156)
--FILE--
<?php
echo sprintf('%b', 5), "\n";
echo sprintf('%x', 255), "\n";
echo sprintf('%c', 65), "\n";
echo sprintf('%g', 1.5), "\n";
--EXPECT--
101
ff
A
1.5
