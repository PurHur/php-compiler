<?php

declare(strict_types=1);

// Compile-only (#11491): fputcsv()/str_getcsv() skip-default named escape:/enclosure:
$f = fopen('php://memory', 'r+');
fputcsv($f, ['a', 'b'], escape: '');
rewind($f);
echo stream_get_contents($f);
echo implode(',', str_getcsv('a,b', escape: '')), "\n";
fputcsv($f, ['x', 'y'], separator: ';', enclosure: '|');
