--TEST--
stdlib mktime() — local timestamp from date parts (UTC CI)
--FILE--
<?php
echo function_exists('mktime') ? '1' : '0';
echo function_exists('gmmktime') ? '1' : '0';
echo "\n";
echo mktime(0, 0, 0, 5, 29, 2026), "\n";
echo mktime(22, 13, 20, 11, 14, 2023), "\n";
--EXPECT--
11
1780012800
1700000000
