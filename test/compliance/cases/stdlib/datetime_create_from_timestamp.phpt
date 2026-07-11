--TEST--
stdlib DateTime::createFromTimestamp() / DateTimeImmutable::createFromTimestamp() (#5973, #9984, ext/date/php_date.c)
--FILE--
<?php
date_default_timezone_set('UTC');
$dt = DateTime::createFromTimestamp(1700000000);
echo $dt->getTimestamp(), "\n";
$di = DateTimeImmutable::createFromTimestamp(1700000000);
echo $di->getTimestamp(), "\n";
var_export($di instanceof DateTimeImmutable);
echo "\n";
$float = DateTime::createFromTimestamp(1700000000.5);
echo $float->format('U.u'), "\n";
try {
    DateTime::createFromTimestamp([]);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1700000000
1700000000
true
1700000000.500000
DateTime::createFromTimestamp(): Argument #1 ($timestamp) must be of type int|float, array given
