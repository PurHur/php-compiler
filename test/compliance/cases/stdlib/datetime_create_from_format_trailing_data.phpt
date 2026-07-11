--TEST--
stdlib DateTime::createFromFormat() trailing junk — getLastErrors slot 10 (#14173, #16196, ext/date/php_date.c)
--FILE--
<?php
DateTime::createFromFormat('Y-m-d', '2020-01-01 extra');
$errors = DateTime::getLastErrors();
echo (string) $errors['error_count'], "\n";
echo $errors['errors'][10] ?? '', "\n";
DateTimeImmutable::createFromFormat('Y-m-d', '2020-01-01 extra');
$immutable = DateTimeImmutable::getLastErrors();
echo (string) $immutable['error_count'], "\n";
echo $immutable['errors'][10] ?? '', "\n";
$ok = DateTime::createFromFormat('Y-m-d', '2020-01-01');
echo $ok instanceof DateTime ? 'ok' : 'fail', "\n";
--EXPECT--
1
Trailing data
1
Trailing data
ok
