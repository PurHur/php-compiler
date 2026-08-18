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
