--TEST--
stdlib DateInterval::createFromDateString() — OOP alias (#9993, ext/date/php_date.c)
--FILE--
<?php
$di = DateInterval::createFromDateString('1 day');
var_export($di instanceof DateInterval);
echo "\n";
if ($di !== false) {
    echo $di->format('%d'), "\n";
}

$bad = DateInterval::createFromDateString('not an interval');
var_export($bad);
echo "\n";

try {
    DateInterval::createFromDateString([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$combo = DateInterval::createFromDateString('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";
?>
--EXPECT--
PHP Warning:  DateInterval::createFromDateString(): Unknown or bad format (not an interval) at position 0 (n): The timezone could not be found in the database
true
1
false
TypeError: DateInterval::createFromDateString(): Argument #1 ($datetime) must be of type string, array given
1:2
