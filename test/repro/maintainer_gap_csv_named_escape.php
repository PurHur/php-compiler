<?php

declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fputcsv($f, ['a', 'b'], escape: '');
rewind($f);
echo stream_get_contents($f);
$row = str_getcsv('a,b', escape: '');
echo implode('|', $row), "\n";
$f = fopen('php://memory', 'r+');
fputcsv($f, ['x', 'y'], separator: ';', enclosure: '|');
rewind($f);
echo stream_get_contents($f);
