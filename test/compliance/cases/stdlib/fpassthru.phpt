--TEST--
stdlib fpassthru() streams remaining handle bytes to stdout (issue #1194)
--FILE--
<?php
$base = 'test/compliance/cases/stdlib/fpassthru_fixture';
$path = $base . '/data.txt';
file_put_contents($path, 'passthru-body');
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
echo $n, "\n";
$bad = fpassthru(99999);
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
passthru-body
13
false
