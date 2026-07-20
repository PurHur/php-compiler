--TEST--
date timezone_open / DateTimeZone / date_default_timezone_set(null) — DEP+coerce on 8.4 (#21369, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
foreach ([
    static fn () => timezone_open(null),
    static fn () => new DateTimeZone(null),
    static fn () => date_default_timezone_set(null),
] as $fn) {
    try {
        var_export($fn());
        echo "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
$zone = timezone_open('UTC');
echo ($zone instanceof DateTimeZone) ? "utc_ok\n" : "utc_bad\n";
echo date_default_timezone_set('UTC') ? "set_ok\n" : "set_bad\n";
?>
--EXPECTF--
PHP Deprecated:  timezone_open(): Passing null to parameter #1 ($timezone) of type string is deprecated in %s on line %d
PHP Warning:  timezone_open(): Unknown or bad timezone () in %s on line %d
PHP Deprecated:  DateTimeZone::__construct(): Passing null to parameter #1 ($timezone) of type string is deprecated in %s on line %d
PHP Deprecated:  date_default_timezone_set(): Passing null to parameter #1 ($timezoneId) of type string is deprecated in %s on line %d
PHP Notice:  date_default_timezone_set(): Timezone ID '' is invalid in %s on line %d
false
DateInvalidTimeZoneException: DateTimeZone::__construct(): Unknown or bad timezone ()
false
utc_ok
set_ok
