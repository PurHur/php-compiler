<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'w+');
fputcsv($fp, [null, 1.5, true, 'a', false, 'b']);
rewind($fp);
echo 'null=', stream_get_contents($fp), "\n";
rewind($fp);
$line = stream_get_contents($fp);
echo 'float=', (str_contains($line, '1.5') ? '1.5' : 'missing'), "\n";
echo 'bool=', (str_contains($line, ',1,') ? '1' : 'missing'), "\n";
echo 'mixed=', trim($line), "\n";
echo "ok\n";
