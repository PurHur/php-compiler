--TEST--
date DateMalformedStringException absent on 8.2 reference profile; bad DateTime throws Exception (#16926, ext/date/php_date.c)
--FILE--
<?php
var_export(class_exists('DateMalformedStringException', false));
echo "\n";
try {
    new DateTime('not-a-valid-date');
    echo "no throw\n";
} catch (DateMalformedStringException $e) {
    echo "wrong: DateMalformedStringException\n";
} catch (Exception $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
Exception
Failed to parse time string (not-a-valid-date) at position 0 (n): The timezone could not be found in the database
