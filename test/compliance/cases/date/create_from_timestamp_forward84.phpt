--TEST--
date DateTime::createFromTimestamp() — present on PHP_COMPILER_PROFILE=8.4 (#22795, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo method_exists(DateTime::class, 'createFromTimestamp') ? "dt-ok\n" : "dt-fail\n";
echo method_exists(DateTimeImmutable::class, 'createFromTimestamp') ? "di-ok\n" : "di-fail\n";
$di = DateTimeImmutable::createFromTimestamp(0);
echo $di->format('U'), "\n";
--EXPECT--
dt-ok
di-ok
0
