--TEST--
stdlib DateTime::diff invert y/m/d borrows month lengths from earlier date (#32075, ext/date/lib/interval.c)
--FILE--
<?php
error_reporting(E_ALL);
$tz = new DateTimeZone('UTC');
$a = new DateTime('2020-03-01', $tz);
$b = new DateTime('2020-01-31', $tz);
$d = $a->diff($b);
echo 'invert m=', $d->m, ' d=', $d->d, ' days=', $d->days, ' inv=', $d->invert, "\n";
$abs = $a->diff($b, true);
echo 'abs m=', $abs->m, ' d=', $abs->d, ' days=', $abs->days, ' inv=', $abs->invert, "\n";
$fwd = $b->diff($a);
echo 'forward m=', $fwd->m, ' d=', $fwd->d, ' days=', $fwd->days, ' inv=', $fwd->invert, "\n";
$a21 = new DateTime('2021-03-01', $tz);
$b21 = new DateTime('2021-01-31', $tz);
$d21 = $a21->diff($b21);
echo 'invert2021 m=', $d21->m, ' d=', $d21->d, ' days=', $d21->days, ' inv=', $d21->invert, "\n";
$proc = date_diff($a, $b);
echo 'date_diff m=', $proc->m, ' d=', $proc->d, ' inv=', $proc->invert, "\n";
$imm = (new DateTimeImmutable('2020-03-01', $tz))->diff(new DateTimeImmutable('2020-01-31', $tz));
echo 'immutable m=', $imm->m, ' d=', $imm->d, ' inv=', $imm->invert, "\n";
?>
--EXPECT--
invert m=1 d=1 days=30 inv=1
abs m=1 d=1 days=30 inv=0
forward m=0 d=30 days=30 inv=0
invert2021 m=1 d=1 days=29 inv=1
date_diff m=1 d=1 inv=1
immutable m=1 d=1 inv=1
