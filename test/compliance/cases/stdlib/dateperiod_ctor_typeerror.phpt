--TEST--
stdlib DatePeriod invalid ctor overload — signature TypeError only (#15431, ext/date/php_date.c)
--FILE--
<?php
try {
    new DatePeriod('2020-01-01', 'P1D', '2020-01-03');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
DatePeriod::__construct() accepts (DateTimeInterface, DateInterval, int [, int]), or (DateTimeInterface, DateInterval, DateTime [, int]), or (string [, int]) as arguments
