--TEST--
stdlib sprintf/printf/vsprintf %.Ng significant digits (#24016, ext/standard/formatted_print.c)
--FILE--
<?php
echo sprintf('%.2g', 1234), "\n";
echo sprintf('%.3g', 1234), "\n";
echo sprintf('%.1g', 1234), "\n";
echo sprintf('%.2g', 12.34), "\n";
echo sprintf('%.2g', 0.01234), "\n";
echo sprintf('%.0g', 1234), "\n";
echo sprintf('%.2G', 1234), "\n";
echo sprintf('%.*g', 2, 1234), "\n";
printf('%.2g', 1234);
echo "\n";
echo vsprintf('%.2g', [1234]), "\n";
echo sprintf('%.2h', 1234), "\n";
--EXPECT--
1.2e+3
1.23e+3
1.0e+3
12
0.012
1.0e+3
1.2E+3
1.2e+3
1.2e+3
1.2e+3
1.2e+3
