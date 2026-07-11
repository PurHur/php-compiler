--TEST--
stdlib DateTime timezone:-only constructor — default now (#12124, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

$tz = new DateTimeZone('UTC');
$dt = new DateTime(timezone: $tz);
$immutable = new DateTimeImmutable(timezone: $tz);
echo ($dt instanceof DateTime) ? "dt_ok\n" : "dt_bad\n";
echo ($immutable instanceof DateTimeImmutable) ? "immutable_ok\n" : "immutable_bad\n";
echo strlen($dt->format('Y-m-d')) === 10 ? "formatted_ok\n" : "formatted_bad\n";
?>
--EXPECT--
dt_ok
immutable_ok
formatted_ok
