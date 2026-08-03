--TEST--
easter_days() AOT Gregorian 2024 (#27358)
--FILE--
<?php
echo easter_days(2024), "\n";
echo easter_days(2023), "\n";
$year = 2024;
echo easter_days($year), "\n";
--EXPECT--
10
19
10
