<?php
// #34611 — literal DatePeriod unserialize foreach without prior `new DatePeriod`
declare(strict_types=1);

$p = unserialize('O:10:"DatePeriod":7:{s:5:"start";O:8:"DateTime":3:{s:4:"date";s:26:"2020-01-01 00:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}s:7:"current";N;s:3:"end";O:8:"DateTime":3:{s:4:"date";s:26:"2020-01-05 00:00:00.000000";s:13:"timezone_type";i:3;s:8:"timezone";s:3:"UTC";}s:8:"interval";O:12:"DateInterval":10:{s:1:"y";i:0;s:1:"m";i:0;s:1:"d";i:1;s:1:"h";i:0;s:1:"i";i:0;s:1:"s";i:0;s:1:"f";d:0;s:6:"invert";i:0;s:4:"days";b:0;s:11:"from_string";b:0;}s:11:"recurrences";i:1;s:18:"include_start_date";b:1;s:16:"include_end_date";b:0;}');
foreach ($p as $d) {
    echo $d->format('Y-m-d'), PHP_EOL;
}
