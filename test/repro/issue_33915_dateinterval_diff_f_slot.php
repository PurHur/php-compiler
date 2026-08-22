<?php
$a = new DateTime('2024-01-01 00:00:00.5');
$b = new DateTime('2024-01-01 00:00:01.25');
$d = $a->diff($b);
echo 'f=', $d->f, "\n";
echo 's=', $d->s, "\n";
echo 'fmt=', $d->format('%f'), "\n";
echo 'done', "\n";
