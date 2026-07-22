--TEST--
stdlib DateTime::createFromInterface() / DateTimeImmutable::createFromInterface() (#5936, ext/date/php_date.c)
--FILE--
<?php
$immutable = new DateTimeImmutable('2024-06-01 12:00:00', new DateTimeZone('UTC'));
$mutable = new DateTime('2024-06-01 12:00:00', new DateTimeZone('UTC'));
var_export(method_exists('DateTime', 'createFromInterface'));
echo "\n";
var_export(method_exists('DateTimeImmutable', 'createFromInterface'));
echo "\n";
$fromImmutable = DateTime::createFromInterface($immutable);
var_export($fromImmutable instanceof DateTime);
echo "\n";
echo $fromImmutable->format('c'), "\n";
$fromMutable = DateTime::createFromInterface($mutable);
var_export($fromMutable instanceof DateTime);
echo "\n";
$immutableCopy = DateTimeImmutable::createFromInterface($mutable);
var_export($immutableCopy instanceof DateTimeImmutable);
echo "\n";
$fromImmutable->setTime(12, 0, 0, 123456);
echo (int) $immutable->format('u'), "\n";
echo (int) $fromImmutable->format('u'), "\n";
try {
    DateTime::createFromInterface(new DateTimeZone('UTC'));
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
2024-06-01T12:00:00+00:00
true
true
0
123456
DateTime::createFromInterface(): Argument #1 ($object) must be of type DateTimeInterface, DateTimeZone given
