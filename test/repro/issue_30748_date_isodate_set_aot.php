<?php
$dt = date_create('2020-01-01');
date_isodate_set($dt, 2020, 1, 1);
echo date_format($dt, 'Y-m-d'), "\n";
$dt2 = date_create('2020-01-01');
date_isodate_set($dt2, 2020, 1);
echo date_format($dt2, 'Y-m-d'), "\n";
$dt3 = date_create('2000-01-01');
date_isodate_set($dt3, 2008, 2, 1);
echo date_format($dt3, 'Y-m-d'), "\n";
$mut = new DateTime('2020-01-15', new DateTimeZone('UTC'));
$mut->setISODate(2020, 1, 1);
echo $mut->format('Y-m-d'), "\n";
$imm = new DateTimeImmutable('2020-01-15', new DateTimeZone('UTC'));
$imm2 = $imm->setISODate(2020, 1, 1);
echo $imm->format('Y-m-d'), "\n";
echo $imm2->format('Y-m-d'), "\n";
echo "ok\n";
