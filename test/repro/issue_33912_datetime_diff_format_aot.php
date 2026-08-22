<?php
// #33912 — DateInterval::format after DateTime::diff must match Zend (not exit mid-main).
$a = new DateTime('2020-01-01');
$b = new DateTime('2020-01-15');
$d = $a->diff($b);
echo 'days=', $d->days, "\n";
echo 'a=', $d->format('%a'), "\n";
echo 'd=', $d->format('%d'), "\n";
echo 'chain=', (new DateTime('2020-01-01'))->diff(new DateTime('2020-01-15'))->format('%a'), "\n";
