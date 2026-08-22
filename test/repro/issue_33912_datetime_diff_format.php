<?php
$a = new DateTime('2024-01-01');
$b = new DateTime('2024-01-15');
$d = $a->diff($b);
echo 'days=', $d->days, "\n";
echo 'a=', $d->format('%a'), "\n";
echo 'd=', $d->format('%d'), "\n";
echo 'chain=', $a->diff($b)->format('%a'), "\n";
echo 'done', "\n";
