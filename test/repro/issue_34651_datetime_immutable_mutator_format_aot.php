<?php
// #34651 — DateTimeImmutable mutation returns must not inherit construct stamp on format().
declare(strict_types=1);

$d = new DateTimeImmutable('2020-01-01');
$e = $d->modify('+1 day');
echo $d->format('Y-m-d'), '|', $e->format('Y-m-d'), PHP_EOL;

echo $d->add(new DateInterval('P1D'))->format('Y-m-d'), PHP_EOL;
echo $d->sub(new DateInterval('P1D'))->format('Y-m-d'), PHP_EOL;
echo (new DateTimeImmutable('2020-01-01 12:00:00'))->setTime(15, 30)->format('H:i'), PHP_EOL;
