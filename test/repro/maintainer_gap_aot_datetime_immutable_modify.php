<?php
// Repro #26789 — DateTimeImmutable::modify under user-script AOT
$d = new DateTimeImmutable('2020-01-01');
$d2 = $d->modify('+1 day');
echo $d->format('Y-m-d'), ',', $d2->format('Y-m-d'), "\n";
