--TEST--
stdlib intval() base 0 autodetect (php-src basic_functions.c / zend_strtol, issue #5461)
--FILE--
<?php
echo intval('08', 0), "\n";
echo intval('0x10', 0), "\n";
echo intval('010', 0), "\n";
--EXPECT--
0
16
8
