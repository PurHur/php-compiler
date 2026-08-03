--TEST--
AOT: easter_date() matches Zend (#27356)
--FILE--
<?php
echo easter_date(2024), PHP_EOL;
$year = 2024;
echo easter_date($year), PHP_EOL;
--EXPECT--
1711843200
1711843200
