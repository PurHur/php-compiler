--TEST--
stdlib date_interval_create_from_date_string() — relative interval parsing (#4606, ext/date/php_date.c)
--FILE--
<?php
echo function_exists('date_interval_create_from_date_string') ? "fn\n" : "no-fn\n";

$iv = date_interval_create_from_date_string('1 day');
var_export($iv instanceof DateInterval);
echo "\n";
if ($iv) {
    echo $iv->format('%d'), "\n";
}

$bad = date_interval_create_from_date_string('not an interval');
var_export($bad);
echo "\n";

try {
    date_interval_create_from_date_string([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$combo = date_interval_create_from_date_string('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";

$plus = date_interval_create_from_date_string('1 day + 2 hours');
echo $plus->d, ':', $plus->h, "\n";

$minus = date_interval_create_from_date_string('1 day - 2 hours');
echo $minus->d, ':', $minus->h, "\n";
?>
--EXPECT--
PHP Warning:  date_interval_create_from_date_string(): Unknown or bad format (not an interval) at position 0 (n): The timezone could not be found in the database
fn
true
1
false
TypeError: date_interval_create_from_date_string(): Argument #1 ($datetime) must be of type string, array given
1:2
1:2
1:-2
