--TEST--
stdlib sscanf()/fscanf() %n conversion (#9325, ext/standard/formatted_io.c)
--FILE--
<?php
declare(strict_types=1);

$n = 0;
$r = sscanf('abc 42', '%s %d %n', $a, $b, $n);
echo $r, ':', $a, ':', $b, ':', $n, "\n";

$parsed = sscanf('abc 42', '%s %d %n');
echo count($parsed), ':', $parsed[2], "\n";

$f = fopen('php://memory', 'r+');
fwrite($f, '42 7');
rewind($f);
$n2 = 0;
$r2 = fscanf($f, '%d %d %n', $fa, $fb, $n2);
fclose($f);
echo $r2, ':', $fa, ':', $fb, ':', $n2, "\n";
--EXPECT--
3:abc:42:6
3:6
3:42:7:4
