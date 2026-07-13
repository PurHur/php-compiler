--TEST--
language nested new in call argument — outer DatePeriod not inner DateInterval (#18501, Zend/zend_compile.c)
--FILE--
<?php
$s = new DateTime('2020-01-01');
$e = new DateTime('2020-01-03');
echo get_class(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
echo iterator_count(new DatePeriod($s, new DateInterval('P1D'), $e)), "\n";
--EXPECT--
DatePeriod
2
