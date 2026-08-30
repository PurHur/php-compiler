<?php
/**
 * AOT: json_encode(DateInterval) — peer of #14144 DatePeriod fold.
 * php-src: ext/json/php_json.c DateInterval json handler.
 */
$di = new DateInterval('P1D');
echo json_encode($di), "\n";
$start = new DateTime('2020-01-01 00:00:00', new DateTimeZone('UTC'));
$end = new DateTime('2020-01-03 00:00:00', new DateTimeZone('UTC'));
echo json_encode($start->diff($end)), "\n";
