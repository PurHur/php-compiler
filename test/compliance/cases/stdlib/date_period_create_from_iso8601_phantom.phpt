--TEST--
stdlib DatePeriod::createFromISO8601String() phantom withheld on 8.2 reference profile (#16796, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

echo method_exists(DatePeriod::class, 'createFromISO8601String') ? "exists\n" : "ok\n";
?>
--EXPECT--
ok
