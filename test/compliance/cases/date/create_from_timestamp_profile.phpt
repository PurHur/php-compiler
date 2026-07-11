--TEST--
date DateTime::createFromTimestamp() — not advertised on PHP 8.2 reference profile (#18027, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(DateTime::class, 'createFromTimestamp') ? "dt-fail\n" : "dt-ok\n";
echo method_exists(DateTimeImmutable::class, 'createFromTimestamp') ? "di-fail\n" : "di-ok\n";
--EXPECT--
dt-ok
di-ok
