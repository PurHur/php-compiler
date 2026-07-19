--TEST--
date timezone_open / DateTimeZone / date_default_timezone_set(null) — TypeError on 8.4 (#20959, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([
    static fn () => timezone_open(null),
    static fn () => new DateTimeZone(null),
    static fn () => date_default_timezone_set(null),
] as $fn) {
    try {
        $fn();
        echo "uncaught\n";
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
$zone = timezone_open('UTC');
echo ($zone instanceof DateTimeZone) ? "utc_ok\n" : "utc_bad\n";
echo date_default_timezone_set('UTC') ? "set_ok\n" : "set_bad\n";
?>
--EXPECT--
timezone_open(): Argument #1 ($timezone) must be of type string, null given
DateTimeZone::__construct(): Argument #1 ($timezone) must be of type string, null given
date_default_timezone_set(): Argument #1 ($timezoneId) must be of type string, null given
utc_ok
set_ok
