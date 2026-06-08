--TEST--
stdlib DateMalformedIntervalException — PHP 8.3 Exception-branch date class (#7129, ext/date/php_date.h)
--FILE--
<?php
var_export(class_exists('DateMalformedIntervalException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedIntervalException', 'DateException'));
echo "\n";
var_export(is_subclass_of('DateMalformedIntervalException', 'Exception'));
echo "\n";
try {
    throw new DateMalformedIntervalException('bad interval');
} catch (DateException $e) {
    echo 'catch DateException ok', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
catch DateException ok
bad interval
