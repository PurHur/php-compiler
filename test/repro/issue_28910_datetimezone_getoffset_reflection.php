<?php
/**
 * #28910 — DateTimeZone::getOffset Reflection param type is DateTimeInterface (php_date.stub.php).
 */
$r = new ReflectionMethod(DateTimeZone::class, 'getOffset');
$p = $r->getParameters()[0];
echo 'name=', $p->getName(), PHP_EOL;
echo 'type=', (string) $p->getType(), PHP_EOL;
$tz = new DateTimeZone('UTC');
$dt = new DateTimeImmutable('2020-01-01', $tz);
echo 'named=', $tz->getOffset(datetime: $dt), PHP_EOL;
