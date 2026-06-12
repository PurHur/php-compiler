--TEST--
stdlib sprintf() extended conversions (#4156, ext/standard/sprintf.c)
--FILE--
<?php
echo sprintf('%b', 5), "\n";
echo sprintf('%x', 255), "\n";
echo sprintf('%X', 255), "\n";
echo sprintf('%o', 8), "\n";
echo sprintf('%u', 1), "\n";
echo sprintf('%c', 65), "\n";
echo sprintf('%e', 1.5), "\n";
echo sprintf('%E', 1.5), "\n";
echo sprintf('%g', 1.5), "\n";
echo sprintf('%G', 1.5), "\n";
--EXPECT--
101
ff
FF
10
1
A
1.500000e+0
1.500000E+0
1.5
1.5
