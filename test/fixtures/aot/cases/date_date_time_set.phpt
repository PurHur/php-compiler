--TEST--
AOT: date_date_set/date_time_set + DateTime(Immutable)::setDate/setTime (#30747)
--FILE--
<?php
$dt = date_create('2020-01-02T03:04:05+00:00');
date_date_set($dt, 2021, 6, 15);
date_time_set($dt, 12, 30, 0);
echo date_format($dt, 'c'), "\n";
$dt2 = date_create('2020-01-02T03:04:05+00:00');
date_time_set($dt2, 8, 9);
echo date_format($dt2, 'Y-m-d H:i:s'), "\n";
$dt3 = date_create('2020-01-02T03:04:05+00:00');
date_time_set($dt3, 1, 2, 3, 4);
echo date_format($dt3, 'Y-m-d H:i:s'), "\n";
$mut = new DateTime('2020-01-02 03:04:05', new DateTimeZone('UTC'));
$mut->setDate(2021, 6, 15);
$mut->setTime(12, 30, 0);
echo $mut->format('c'), "\n";
$imm = new DateTimeImmutable('2020-01-02 03:04:05', new DateTimeZone('UTC'));
$imm2 = $imm->setDate(2021, 6, 15)->setTime(12, 30);
echo $imm->format('Y-m-d'), "\n";
echo $imm2->format('c'), "\n";
?>
--EXPECT--
2021-06-15T12:30:00+00:00
2020-01-02 08:09:00
2020-01-02 01:02:03
2021-06-15T12:30:00+00:00
2020-01-02
2021-06-15T12:30:00+00:00
