--TEST--
stdlib fputcsv() $eol 6th parameter (#19368)
--FILE--
<?php
declare(strict_types=1);

echo (new ReflectionFunction('fputcsv'))->getNumberOfParameters(), "\n";

$f = fopen('php://memory', 'r+');
$n = fputcsv($f, ['a', 'b'], ',', '"', '\\', "\r\n");
rewind($f);
$line = stream_get_contents($f);
echo $n, ' ', bin2hex($line), "\n";

$f2 = fopen('php://memory', 'r+');
fputcsv($f2, ['x'], eol: "\r\n");
rewind($f2);
echo bin2hex(stream_get_contents($f2)), "\n";

$f3 = fopen('php://memory', 'r+');
fputcsv($f3, ['y']);
rewind($f3);
echo bin2hex(stream_get_contents($f3)), "\n";
--EXPECT--
6
5 612c620d0a
780d0a
790a
