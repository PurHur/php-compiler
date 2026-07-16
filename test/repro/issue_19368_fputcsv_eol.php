<?php
declare(strict_types=1);

echo 'params=', (new ReflectionFunction('fputcsv'))->getNumberOfParameters(), "\n";
$f = fopen('php://memory', 'r+');
$n = fputcsv($f, ['a', 'b'], ',', '"', '\\', "\r\n");
rewind($f);
echo 'n=', $n, ' hex=', bin2hex(stream_get_contents($f)), "\n";
$f2 = fopen('php://memory', 'r+');
fputcsv($f2, ['c'], eol: "\r\n");
rewind($f2);
echo 'named hex=', bin2hex(stream_get_contents($f2)), "\n";
