--TEST--
stdlib sprintf/printf %f large float precision matches Zend (#26207, main/snprintf.c)
--FILE--
<?php
declare(strict_types=1);
echo sprintf('%f', 1e20), "\n";
echo sprintf('%.0f', 1e20), "\n";
echo sprintf('%.2f', 1e20), "\n";
echo sprintf('%.6f', 1e20), "\n";
echo sprintf('%F', 1e20), "\n";
echo sprintf('%f', 1.5), "\n";
echo sprintf('%.2f', M_PI), "\n";
--EXPECT--
100000000000000000000.000000
100000000000000000000
100000000000000000000.00
100000000000000000000.000000
100000000000000000000.000000
1.500000
3.14
