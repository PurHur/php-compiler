<?php
$a = new DateTime('2024-01-01 00:00:00.5');
$b = new DateTime('2024-01-01 00:00:01.25');
$d = $a->diff($b);
echo $d->f, "\n";
echo $d->s, "\n";
echo $d->format('%f'), "\n";
echo $d->format('%s'), "\n";
echo 'done', "\n";
