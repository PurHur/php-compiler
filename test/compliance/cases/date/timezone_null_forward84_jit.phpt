--TEST--
date timezone_open / date_default_timezone_set(null) — DEP+coerce JIT on 8.4 (#21369, ext/date/php_date.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
error_reporting(E_ALL);
try {
    var_export(timezone_open(null));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo date_default_timezone_set(null) ? "set_true\n" : "set_false\n";
$zone = timezone_open('UTC');
echo ($zone instanceof DateTimeZone) ? "utc_ok\n" : "utc_bad\n";
?>
--EXPECTF--
PHP Deprecated:  timezone_open(): Passing null to parameter #1 ($timezone) of type string is deprecated in %s on line %d
PHP Warning:  timezone_open(): Unknown or bad timezone () in %s on line %d
PHP Deprecated:  date_default_timezone_set(): Passing null to parameter #1 ($timezoneId) of type string is deprecated in %s on line %d
PHP Notice:  date_default_timezone_set(): Timezone ID '' is invalid in %s on line %d
false
set_false
utc_ok
