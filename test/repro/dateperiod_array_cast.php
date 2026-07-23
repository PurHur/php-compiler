<?php
declare(strict_types=1);

$dp = new DatePeriod(new DateTime('2020-01-01 UTC'), new DateInterval('P1D'), 2);
$a = (array) $dp;
echo array_key_exists('current', $a) && null === $a['current'] ? "current=null\n" : "current=missing\n";
echo array_key_exists('end', $a) && null === $a['end'] ? "end=null\n" : "end=missing\n";
var_export($a);
echo "\n";
