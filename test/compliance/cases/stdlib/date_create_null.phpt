--TEST--
stdlib date_create(null) / DateTime(null) — null datetime deprecated on 8.2 profile (#18682, ext/date/php_date.c)
--FILE--
<?php
error_reporting(E_ALL);
date_create(null);
new DateTime(null);
new DateTimeImmutable(null);
date_create_immutable(null);
echo "done\n";
?>
--EXPECTF--
PHP Deprecated:  date_create(): Passing null to parameter #1 ($datetime) of type string is deprecated in %s on line %d
PHP Deprecated:  DateTime::__construct(): Passing null to parameter #1 ($datetime) of type string is deprecated in %s on line %d
PHP Deprecated:  DateTimeImmutable::__construct(): Passing null to parameter #1 ($datetime) of type string is deprecated in %s on line %d
PHP Deprecated:  date_create_immutable(): Passing null to parameter #1 ($datetime) of type string is deprecated in %s on line %d
done
