--TEST--
stdlib DateMalformedPeriodException — PHP 8.3 Exception-branch date class (#7129, ext/date/php_date.h)
--FILE--
<?php
var_export(class_exists('DateMalformedPeriodException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedPeriodException', 'DateException'));
echo "\n";
var_export(is_subclass_of('DateMalformedPeriodException', 'Exception'));
echo "\n";
try {
    throw new DateMalformedPeriodException('bad period');
} catch (DateException $e) {
    echo 'catch DateException ok', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
catch DateException ok
bad period
