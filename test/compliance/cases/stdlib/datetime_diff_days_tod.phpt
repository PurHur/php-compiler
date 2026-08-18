--TEST--
stdlib DateTime::diff DateInterval::$days decrements when later TOD is earlier (#32074, ext/date/lib/interval.c)
--FILE--
<?php
error_reporting(E_ALL);
$tz = new DateTimeZone('UTC');
$a = new DateTime('2020-01-31 12:00:00', $tz);
$b = new DateTime('2020-03-01 06:00:00', $tz);
$d = $a->diff($b);
$proc = date_diff($a, $b);
echo 'tod d=', $d->d, ' h=', $d->h, ' days=', $d->days, ' a=', $d->format('%a'),
    ' date_diff_days=', $proc->days, "\n";
$a2 = new DateTime('2020-01-31', $tz);
$b2 = new DateTime('2020-03-01', $tz);
$d2 = $a2->diff($b2);
echo 'midnight days=', $d2->days, ' a=', $d2->format('%a'), "\n";
$ny = new DateTimeZone('America/New_York');
$n1 = new DateTime('2026-03-07 12:00:00', $ny);
$n2 = new DateTime('2026-03-09 12:00:00', $ny);
$dn = $n1->diff($n2);
echo 'dst d=', $dn->d, ' h=', $dn->h, ' days=', $dn->days, "\n";
$imm = (new DateTimeImmutable('2020-01-31 12:00:00', $tz))
    ->diff(new DateTimeImmutable('2020-03-01 06:00:00', $tz));
echo 'immutable days=', $imm->days, "\n";
?>
--EXPECT--
tod d=29 h=18 days=29 a=29 date_diff_days=29
midnight days=30 a=30
dst d=2 h=0 days=2
immutable days=29
