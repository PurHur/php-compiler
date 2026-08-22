<?php
// AOT: reassignment of DateTime(Immutable) mutate result onto the same local (#33929).
$d = new DateTimeImmutable('2020-01-01');
$d = $d->modify('+1 day');
echo $d->format('Y-m-d'), "\n";
$e = new DateTime('2020-01-01');
$e = $e->modify('+1 day');
echo $e->format('Y-m-d'), "\n";
$f = new DateTimeImmutable('2020-01-01');
$f = $f->setDate(2021, 2, 3);
echo $f->format('Y-m-d'), "\n";
