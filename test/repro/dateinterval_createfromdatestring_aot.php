<?php
// Repro #33878 — AOT DateInterval::createFromDateString cstr→__string__separate + add via boxed object.
$i = DateInterval::createFromDateString('1 day + 2 hours');
var_dump($i->d, $i->h);
$dt = new DateTime('2020-01-01 00:00:00');
$dt->add($i);
echo $dt->format('Y-m-d H:i'), "\n";
$j = date_interval_create_from_date_string('3 days');
var_dump($j->d);
