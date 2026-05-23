--TEST--
stdlib fpassthru() streams remaining handle bytes to stdout (issue #1194)
--FILE--
<?php
$path = 'test/compliance/cases/stdlib/fpassthru_fixture/data.txt';
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
echo $n, "\n";
$bad = fpassthru(99999);
echo $bad === false ? 'false' : 'bad', "\n";
--EXPECT--
passthru bytes
15
false
