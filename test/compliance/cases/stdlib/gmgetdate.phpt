--TEST--
stdlib gmgetdate() phantom absent — php-src has getdate/gmdate only (#24608)
--FILE--
<?php
echo 'gmgetdate=', function_exists('gmgetdate') ? '1' : '0', "\n";
echo 'getdate=', function_exists('getdate') ? '1' : '0', "\n";
echo 'gmdate=', function_exists('gmdate') ? '1' : '0', "\n";
$d = getdate(946684800);
echo $d['year'], '-', $d['mon'], '-', $d['mday'], "\n";
echo gmmktime(22, 13, 20, 11, 14, 2023), "\n";
--EXPECT--
gmgetdate=0
getdate=1
gmdate=1
2000-1-1
1700000000
