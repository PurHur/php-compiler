--TEST--
stdlib DateTimeZone invalid id — DateInvalidTimeZoneException not fatal (#7279, ext/date/php_date.c)
--FILE--
<?php
var_export(class_exists('DateException', false));
echo "\n";
var_export(class_exists('DateInvalidTimeZoneException', false));
echo "\n";
var_export(is_subclass_of('DateInvalidTimeZoneException', 'DateException'));
echo "\n";
try {
    new DateTimeZone('Not/A/Timezone');
    echo "no throw\n";
} catch (DateInvalidTimeZoneException $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
DateTimeZone::__construct(): Unknown or bad timezone (Not/A/Timezone)
