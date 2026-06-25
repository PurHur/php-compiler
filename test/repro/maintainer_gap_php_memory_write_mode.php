<?php

declare(strict_types=1);

$h = fopen('php://memory', 'w');
fwrite($h, 'data');
rewind($h);
$w = stream_get_contents($h);
fclose($h);

$h2 = fopen('php://memory', 'wb');
fwrite($h2, 'data');
rewind($h2);
$wb = stream_get_contents($h2);
fclose($h2);

if ('data' !== $w || 'data' !== $wb) {
    echo 'FAIL w=', var_export($w, true), ' wb=', var_export($wb, true), "\n";
    exit(1);
}

echo "w={$w} wb={$wb}\n";
