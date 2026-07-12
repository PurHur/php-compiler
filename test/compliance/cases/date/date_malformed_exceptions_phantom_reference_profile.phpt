--TEST--
date DateMalformed* exception classes absent on 8.2 reference profile (#16888, ext/date/php_date.c)
--FILE--
<?php
var_export(class_exists('DateMalformedIntervalException', false));
echo "\n";
var_export(class_exists('DateMalformedStringException', false));
echo "\n";
var_export(class_exists('DateMalformedPeriodException', false));
echo "\n";
--EXPECT--
false
false
false
