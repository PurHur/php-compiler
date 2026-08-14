--TEST--
DateTime/DateTimeImmutable setTimestamp/getLastErrors excess argc ArgumentCountError (#30991, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = new DateTime('2020-01-01');
echo 'DateTime::setTimestamp+1: ';
try {
    $d->setTimestamp(0, 1);
    echo "ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
$i = new DateTimeImmutable('2020-01-01');
echo 'Immutable::setTimestamp+1: ';
try {
    $i->setTimestamp(0, 1);
    echo "ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo 'DateTime::getLastErrors+1: ';
try {
    DateTime::getLastErrors(1);
    echo "ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo 'Immutable::getLastErrors+1: ';
try {
    DateTimeImmutable::getLastErrors(1);
    echo "ACCEPTED\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
DateTime::setTimestamp+1: DateTime::setTimestamp() expects exactly 1 argument, 2 given
Immutable::setTimestamp+1: DateTimeImmutable::setTimestamp() expects exactly 1 argument, 2 given
DateTime::getLastErrors+1: DateTime::getLastErrors() expects exactly 0 arguments, 1 given
Immutable::getLastErrors+1: DateTimeImmutable::getLastErrors() expects exactly 0 arguments, 1 given
