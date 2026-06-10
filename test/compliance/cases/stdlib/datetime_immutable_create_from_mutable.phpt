--TEST--
stdlib DateTimeImmutable::createFromMutable() — immutable copy from mutable snapshot (#6197, ext/date/php_datetime.c)
--FILE--
<?php
$mutable = new DateTime('2024-06-01 12:00:00', new DateTimeZone('UTC'));
$immutable = DateTimeImmutable::createFromMutable($mutable);
var_export($immutable instanceof DateTimeImmutable);
echo "\n";
echo $immutable->format('c'), "\n";
$mutable->setMicrosecond(123456);
var_export($mutable->getMicrosecond());
echo "\n";
var_export($immutable->getMicrosecond());
echo "\n";
try {
    DateTimeImmutable::createFromMutable(new DateTimeZone('UTC'));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
2024-06-01T12:00:00+00:00
123456
0
DateTimeImmutable::createFromMutable(): Argument #1 ($object) must be of type DateTime, DateTimeZone given
