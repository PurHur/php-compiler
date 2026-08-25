<?php
// #34610 — AOT unserialize(DateTime)::format(T/e/O/P) must not SIGABRT (peer #34594/#33939).
declare(strict_types=1);

$d = unserialize(serialize(new DateTime('2020-01-02 03:04:05', new DateTimeZone('UTC'))));
echo $d->format('T'), PHP_EOL;
echo $d->format('e'), PHP_EOL;
echo $d->format('O'), PHP_EOL;
echo $d->format('P'), PHP_EOL;
echo $d->format('Y-m-d H:i:s T'), PHP_EOL;

$di = unserialize(serialize(new DateTimeImmutable('2020-01-02', new DateTimeZone('UTC'))));
echo $di->format('T'), PHP_EOL;

$ny = unserialize(serialize(new DateTime('2020-01-02 12:00:00', new DateTimeZone('America/New_York'))));
echo $ny->format('T'), PHP_EOL;
echo $ny->format('e'), PHP_EOL;
