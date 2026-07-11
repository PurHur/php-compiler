--TEST--
stdlib DateTime::getMicrosecond() — not advertised on PHP 8.2 reference profile (#14503, ext/date/php_date.c)
--FILE--
<?php
echo method_exists(DateTime::class, 'getMicrosecond') ? "fail\n" : "ok\n";
echo method_exists(DateTimeImmutable::class, 'setMicrosecond') ? "fail\n" : "ok\n";
--EXPECT--
ok
ok
