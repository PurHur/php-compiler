--TEST--
stdlib php://memory fopen write-only readback after rewind (#11636, ext/standard/streams.c)
--FILE--
<?php
declare(strict_types=1);

$h = fopen('php://memory', 'w');
fwrite($h, 'data');
rewind($h);
echo stream_get_contents($h), "\n";
fclose($h);

$h2 = fopen('php://memory', 'wb');
fwrite($h2, 'data');
rewind($h2);
echo stream_get_contents($h2), "\n";
fclose($h2);
--EXPECT--
data
data
