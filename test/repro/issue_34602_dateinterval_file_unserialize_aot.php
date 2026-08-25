<?php
// #34602 residual — true runtime payload (file_get_contents), not serialize() fold / NestedJIT literal.
declare(strict_types=1);
$path = sys_get_temp_dir() . '/di_34602.ser';
file_put_contents($path, serialize(new DateInterval('P1Y2M3DT4H5M6S')));
$u = unserialize(file_get_contents($path));
echo get_class($u), PHP_EOL;
echo $u->y, '-', $u->m, '-', $u->d, ' ', $u->h, ':', $u->i, ':', $u->s, PHP_EOL;
echo $u->format('%Y-%M-%D %H:%I:%S'), PHP_EOL;
