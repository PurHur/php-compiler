--TEST--
range(null, 3) under strict_types coerces on default/Zend-8.2 profile (#29767)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
echo implode(',', range(null, 3)), "\n";
echo implode(',', range(0, null)), "\n";
--EXPECT--
0,1,2,3
0
