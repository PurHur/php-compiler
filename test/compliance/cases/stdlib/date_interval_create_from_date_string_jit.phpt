--TEST--
stdlib date_interval_create_from_date_string() JIT/AOT — relative interval parsing (#4606)
--FILE--
<?php
$iv = date_interval_create_from_date_string('1 day');
var_export($iv instanceof DateInterval);
echo "\n";
if ($iv) {
    echo $iv->format('%d'), "\n";
}

$bad = date_interval_create_from_date_string('not an interval');
var_export($bad);
echo "\n";

$combo = date_interval_create_from_date_string('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";
?>
--EXPECT--
PHP Warning:  date_interval_create_from_date_string(): Unknown or bad format (not an interval) at position 0 (n): The timezone could not be found in the database
true
1
false
1:2
