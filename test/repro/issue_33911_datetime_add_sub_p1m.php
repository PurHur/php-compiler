<?php
$d = new DateTime('2020-01-15');
$d->add(new DateInterval('P1M'));
echo 'add=', $d->format('Y-m-d'), "\n";

$d2 = new DateTime('2020-03-15');
$d2->sub(new DateInterval('P1M'));
echo 'sub=', $d2->format('Y-m-d'), "\n";

$i = DateInterval::createFromDateString('1 day + 2 hours');
$dt = new DateTime('2020-01-01 00:00:00');
$dt->add($i);
echo 'cfd=', $dt->format('Y-m-d H:i'), "\n";
