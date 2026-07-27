--TEST--
Stdlib: DateTimeImmutable::modify relative first/last day / week / back|front of (#23936)
--FILE--
<?php
declare(strict_types=1);
$base = new DateTimeImmutable('2024-03-31 12:00:00');
echo $base->modify('last day of previous month')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('first day of January next year')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('monday this week')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('back of 9am')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('front of 5pm')->format('Y-m-d H:i:s'), "\n";
echo $base->modify('first day of next month')->format('Y-m-d H:i:s'), "\n";
$i = DateInterval::createFromDateString('last day of next month');
echo 'iv_m=', (false === $i ? 'false' : (string) $i->m), "\n";
$i2 = DateInterval::createFromDateString('next Monday');
echo 'iv_wd=', (false === $i2 ? 'false' : 'ok'), "\n";
?>
--EXPECT--
2024-02-29 12:00:00
2025-01-01 12:00:00
2024-03-25 00:00:00
2024-03-31 09:15:00
2024-03-31 16:45:00
2024-04-01 12:00:00
iv_m=1
iv_wd=ok
