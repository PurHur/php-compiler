--TEST--
stdlib DateTime::getLastErrors() error_count sparse timelib slots (#14173, ext/date/php_datetime.c)
--FILE--
<?php
DateTime::createFromFormat('Y-m-d', 'bad');
$errors = DateTime::getLastErrors();
echo (string) $errors['error_count'], "\n";
DateTimeImmutable::createFromFormat('Y-m-d', 'bad');
$immutable = DateTimeImmutable::getLastErrors();
echo (string) $immutable['error_count'], "\n";
echo "ok\n";
--EXPECT--
3
3
ok
