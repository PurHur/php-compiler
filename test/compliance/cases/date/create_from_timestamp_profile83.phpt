--TEST--
date DateTime::createFromTimestamp() — withheld on PHP_COMPILER_PROFILE=8.3 (#22795, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo method_exists(DateTime::class, 'createFromTimestamp') ? "dt-fail\n" : "dt-ok\n";
echo method_exists(DateTimeImmutable::class, 'createFromTimestamp') ? "di-fail\n" : "di-ok\n";
echo method_exists(DateTimeImmutable::class, 'getMicrosecond') ? "us-fail\n" : "us-ok\n";
--EXPECT--
dt-ok
di-ok
us-ok
