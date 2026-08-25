<?php
// #34599 — AOT `$s=serialize($di|$tz); unserialize($s)` must restore Zend date wire
// (peer #34594 DateTime fold / #34584 serialize).
declare(strict_types=1);

$i = new DateInterval('P1Y2M3DT4H5M6S');
$s = serialize($i);
$u = unserialize($s);
echo $u->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;

$z = new DateTimeZone('Europe/Berlin');
$sz = serialize($z);
$uz = unserialize($sz);
echo $uz->getName(), PHP_EOL;

// Folded one-expression path.
echo unserialize(serialize(new DateInterval('PT2H30M')))->format('%H:%I'), PHP_EOL;
echo unserialize(serialize(new DateTimeZone('UTC')))->getName(), PHP_EOL;

// Peer DateTime assigned path must stay green (#34594).
$d = new DateTime('2020-01-02', new DateTimeZone('UTC'));
$sd = serialize($d);
$ud = unserialize($sd);
echo $ud->format('Y-m-d'), PHP_EOL;
