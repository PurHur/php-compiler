--TEST--
stdlib DateTime::createFromImmutable() — mutable copy from immutable snapshot (#6518, ext/date/php_datetime.c)
--FILE--
<?php
$immutable = new DateTimeImmutable('2024-06-01 12:00:00', new DateTimeZone('UTC'));
$mutable = DateTime::createFromImmutable($immutable);
var_export($mutable instanceof DateTime);
echo "\n";
echo $mutable->format('c'), "\n";
$mutable->setMicrosecond(123456);
var_export($immutable->getMicrosecond());
echo "\n";
var_export($mutable->getMicrosecond());
echo "\n";
try {
    DateTime::createFromImmutable(new DateTimeZone('UTC'));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
2024-06-01T12:00:00+00:00
0
123456
DateTime::createFromImmutable(): Argument #1 ($object) must be of type DateTimeImmutable, DateTimeZone given
