--TEST--
stdlib DateTime getMicrosecond/setMicrosecond phantom withheld on reference (#22374, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);
$d = new DateTimeImmutable('2024-01-01 00:00:00.123456');
echo method_exists($d, 'getMicrosecond') ? "get\n" : "ok-get\n";
echo method_exists($d, 'setMicrosecond') ? "set\n" : "ok-set\n";
?>
--EXPECT--
ok-get
ok-set
