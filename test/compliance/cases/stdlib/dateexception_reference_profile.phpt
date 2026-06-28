--TEST--
stdlib DateException/DateError hierarchy absent on 8.2 reference profile (#13118, ext/date/php_date.h)
--FILE--
<?php
var_export(class_exists('DateException', false));
echo "\n";
var_export(class_exists('DateError', false));
echo "\n";
var_export(class_exists('DateInvalidTimeZoneException', false));
echo "\n";
try {
    new DateTimeZone('Not/A/Timezone');
    echo "no throw\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
false
false
DateTimeZone::__construct(): Unknown or bad timezone (Not/A/Timezone)
