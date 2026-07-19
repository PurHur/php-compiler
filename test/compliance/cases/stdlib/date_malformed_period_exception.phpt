--TEST--
stdlib DateMalformedPeriodStringException — php-src name; phantom PeriodException absent (#20779)
--FILE--
<?php
var_export(class_exists('DateMalformedPeriodStringException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedPeriodStringException', 'DateException'));
echo "\n";
var_export(class_exists('DateMalformedPeriodException', false));
echo "\n";
try {
    throw new DateMalformedPeriodStringException('bad period');
} catch (DateException $e) {
    echo 'catch DateException ok', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
false
catch DateException ok
bad period
