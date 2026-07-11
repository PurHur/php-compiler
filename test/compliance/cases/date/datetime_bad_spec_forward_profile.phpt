--TEST--
date DateMalformedStringException on forward 8.4 profile (#16926, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(class_exists('DateMalformedStringException', false));
echo "\n";
var_export(is_subclass_of('DateMalformedStringException', 'DateException'));
echo "\n";
try {
    new DateTime('not-a-valid-date');
    echo "no throw\n";
} catch (DateMalformedStringException $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
DateMalformedStringException
Failed to parse time string (not-a-valid-date) at position 0 (n): The timezone could not be found in the database
