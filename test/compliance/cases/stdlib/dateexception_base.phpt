--TEST--
stdlib DateException base class — Exception-branch date hierarchy root (#7277, #20779)
--FILE--
<?php
var_export(class_exists('DateException', false));
echo "\n";
var_export(is_subclass_of('DateInvalidTimeZoneException', 'DateException'));
echo "\n";
var_export(is_subclass_of('DateMalformedIntervalStringException', 'DateException'));
echo "\n";
var_export(is_subclass_of('DateMalformedPeriodStringException', 'DateException'));
echo "\n";
try {
    throw new DateInvalidTimeZoneException('tz test');
} catch (DateException $e) {
    echo 'catch DateException ok', "\n";
}
--EXPECT--
true
true
true
true
catch DateException ok
