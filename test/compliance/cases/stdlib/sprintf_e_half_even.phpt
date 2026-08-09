--TEST--
stdlib sprintf() %e/%E half-even mantissa rounding (ext/standard/formatted_print.c, #29008)
--FILE--
<?php
echo sprintf('%.3e', 1.2345), "\n";
echo sprintf('%.3e', 1234.5), "\n";
echo sprintf('%.3e', 12345.0), "\n";
echo sprintf('%.3e', 1.2355), "\n";
echo sprintf('%.3e', 0.0012345), "\n";
echo sprintf('%.3e', 9.9995), "\n";
echo sprintf('%.3e', 99.995), "\n";
echo sprintf('%.3E', -1.2345), "\n";
echo sprintf('%.0e', 1.5), "\n";
echo sprintf('%e', 1234.5), "\n";
?>
--EXPECT--
1.234e+0
1.234e+3
1.234e+4
1.236e+0
1.234e-3
9.999e+0
1.000e+2
-1.234E+0
2e+0
1.234500e+3
