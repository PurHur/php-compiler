--TEST--
stdlib DateMalformedIntervalStringException — PHP 8.3+ date class (#20779, ext/date/php_date.stub.php)
--FILE--
<?php
var_export(class_exists('DateMalformedIntervalStringException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedIntervalStringException', 'DateException'));
echo "\n";
var_export(is_subclass_of('DateMalformedIntervalStringException', 'Exception'));
echo "\n";
var_export(class_exists('DateMalformedIntervalException', false));
echo "\n";
try {
    throw new DateMalformedIntervalStringException('bad interval');
} catch (DateException $e) {
    echo 'catch DateException ok', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
false
catch DateException ok
bad interval
