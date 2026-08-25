<?php
// #34651 — AOT DateTimeImmutable mutator returns must not format as construct stamp
declare(strict_types=1);

$d = new DateTimeImmutable('2020-01-01');
$e = $d->modify('+1 day');
echo $d->format('Y-m-d'), '|', $e->format('Y-m-d'), '|', $e->getTimestamp(), PHP_EOL;

$d2 = new DateTimeImmutable('2020-01-01');
echo $d2->add(new DateInterval('P1D'))->format('Y-m-d'), PHP_EOL;

$d3 = new DateTimeImmutable('2020-01-01');
echo $d3->sub(new DateInterval('P1D'))->format('Y-m-d'), PHP_EOL;

$d4 = new DateTimeImmutable('2020-01-01 12:00:00');
echo $d4->setTime(15, 30)->format('H:i'), PHP_EOL;

// Mutable peer must stay green (#33935).
$m = new DateTime('2020-01-01');
$m->modify('+1 day');
echo $m->format('Y-m-d'), PHP_EOL;
