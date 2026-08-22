<?php
// #33922 — DateTime::modify after new must compile and match Zend (incl. fractional).
$d = new DateTime('2020-01-01');
$d->modify('+1 day');
echo 'day=', $d->format('Y-m-d'), "\n";

$f = new DateTime('2020-01-01 00:00:00.5');
$f->modify('+1 second');
echo 'frac=', $f->format('Y-m-d H:i:s.u'), "\n";
