<?php
// AOT DateTime setTimestamp / setDate / setTime after new — peer #33911 stamps (#33925)
$d = new DateTime('@0');
$d->setTimestamp(86400);
echo $d->format('Y-m-d'), "\n";

$d2 = new DateTime('2020-01-01');
$d2->setDate(2021, 2, 3);
echo $d2->format('Y-m-d'), "\n";

$d3 = new DateTime('2020-01-01');
$d3->setTime(12, 30, 45);
echo $d3->format('H:i:s'), "\n";
