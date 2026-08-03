--TEST--
date date('Y', strtotime()) AOT/VM year (#27121, ext/date/php_date.c)
--FILE--
<?php
echo date('Y', strtotime('2020-01-02')), "\n";
echo date('Y-m-d', strtotime('2020-01-02')), "\n";
echo gmdate('Y', 1577923200), "\n";
?>
--EXPECT--
2020
2020-01-02
2020
