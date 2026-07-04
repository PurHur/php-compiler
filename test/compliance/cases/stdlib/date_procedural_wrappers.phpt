--TEST--
stdlib date_* procedural wrappers — date_format/timestamp/timezone/get_last_errors (#9219, ext/date/php_date.c)
--FILE--
<?php
$fs = [
    'date_format',
    'date_timestamp_get',
    'date_timestamp_set',
    'date_timezone_get',
    'date_timezone_set',
    'date_get_last_errors',
];
foreach ($fs as $f) {
    echo $f, ' exists? ', (function_exists($f) ? 'yes' : 'no'), "\n";
}

$dt = date_create('2020-01-02T03:04:05+00:00');
var_dump(date_format($dt, 'c'));
var_dump(date_timestamp_get($dt));

$tz = date_timezone_get($dt);
echo get_class($tz), ':', $tz->getName(), "\n";

$dt2 = date_create('2020-01-01');
date_timestamp_set($dt2, 1577836800);
var_dump(date_format($dt2, 'Y-m-d'));

$dt3 = date_create('2020-01-01', new DateTimeZone('UTC'));
date_timezone_set($dt3, new DateTimeZone('Europe/Berlin'));
echo date_timezone_get($dt3)->getName(), "\n";

var_dump(date_get_last_errors());

date_create_from_format('Y-m-d', 'not-a-date');
$errors = date_get_last_errors();
var_export(is_array($errors));
echo "\n";
var_export($errors['error_count'] > 0);
echo "\n";
?>
--EXPECT--
date_format exists? yes
date_timestamp_get exists? yes
date_timestamp_set exists? yes
date_timezone_get exists? yes
date_timezone_set exists? yes
date_get_last_errors exists? yes
string(25) "2020-01-02T03:04:05+00:00"
int(1577934245)
DateTimeZone:UTC
string(10) "2020-01-01"
Europe/Berlin
bool(false)
true
true
