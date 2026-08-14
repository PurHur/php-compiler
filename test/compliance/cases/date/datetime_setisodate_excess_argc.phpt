--TEST--
DateTime/DateTimeImmutable::setISODate() excess argc ArgumentCountError (#30992, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach ([new DateTime('2020-01-01'), new DateTimeImmutable('2020-01-01')] as $d) {
    echo get_class($d), ': ';
    try {
        $d->setISODate(2020, 1, 1, 1);
        echo "ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
--EXPECT--
DateTime: DateTime::setISODate() expects at most 3 arguments, 4 given
DateTimeImmutable: DateTimeImmutable::setISODate() expects at most 3 arguments, 4 given
