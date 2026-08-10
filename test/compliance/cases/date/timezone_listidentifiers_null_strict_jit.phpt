--TEST--
date DateTimeZone::listIdentifiers / timezone_identifiers_list(null) JIT TypeError under strict_types (#29844, ext/date/php_date.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);
foreach ([
    'oop' => static fn () => DateTimeZone::listIdentifiers(null),
    'proc' => static fn () => timezone_identifiers_list(null),
] as $name => $call) {
    try {
        $call();
        echo "{$name}: uncaught\n";
    } catch (Throwable $e) {
        echo "{$name}: ", get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
oop: TypeError
DateTimeZone::listIdentifiers(): Argument #1 ($timezoneGroup) must be of type int, null given
proc: TypeError
timezone_identifiers_list(): Argument #1 ($timezoneGroup) must be of type int, null given
