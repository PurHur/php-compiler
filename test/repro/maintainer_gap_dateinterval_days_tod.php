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
echo 'midnight days=', $d2->days, "\n";
$ny = new DateTimeZone('America/New_York');
$n1 = new DateTime('2026-03-07 12:00:00', $ny);
$n2 = new DateTime('2026-03-09 12:00:00', $ny);
$dn = $n1->diff($n2);
echo 'dst d=', $dn->d, ' h=', $dn->h, ' days=', $dn->days, "\n";
