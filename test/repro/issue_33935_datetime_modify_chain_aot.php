<?php
// #33935 — fluent DateTime::modify()->modify() must accumulate like Zend.
$d = new DateTime('2024-06-01');
$d->modify('+1 day')->modify('+2 hours');
echo 'chain_day_hours=', $d->format('Y-m-d H:i:s'), "\n";

$e = new DateTime('2024-06-01');
$e->modify('+2 hours')->modify('+1 day');
echo 'chain_hours_day=', $e->format('Y-m-d H:i:s'), "\n";

$f = new DateTime('2024-06-01');
$f->modify('+1 day')->modify('+1 day');
echo 'chain_day_day=', $f->format('Y-m-d H:i:s'), "\n";

$g = new DateTime('2024-06-01');
$g->modify('+1 day');
$g->modify('+2 hours');
echo 'sep_day_hours=', $g->format('Y-m-d H:i:s'), "\n";
