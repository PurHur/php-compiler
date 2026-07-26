<?php
// Repro #23275 — mktime/gmmktime Zend stub minute/second/month named parameters
date_default_timezone_set('UTC');
$ok = true;
foreach (['mktime', 'gmmktime'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    if (['hour', 'minute', 'second', 'month', 'day', 'year'] !== $names) {
        $ok = false;
        break;
    }
    $ts = $fn(hour: 12, minute: 0, second: 0, month: 1, day: 1, year: 2020);
    if (!is_int($ts) || $ts <= 0) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
