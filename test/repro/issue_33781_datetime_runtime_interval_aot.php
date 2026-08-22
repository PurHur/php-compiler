<?php
// #33781 — runtime DateInterval variable for DateTime{,Immutable}::add/sub
$i = new DateInterval('P1M');
echo (new DateTimeImmutable('2020-01-15'))->add($i)->format('Y-m-d'), "\n";
$i2 = new DateInterval('P1D');
$d = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$d->add($i2);
echo $d->format('Y-m-d'), "\n";
