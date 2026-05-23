--TEST--
AOT: fpassthru() streams remaining handle bytes to stdout (issue #1194)
--FILE--
<?php
$path = 'test/fixtures/aot/cases/fpassthru_fixture/data.txt';
$h = fopen($path, 'r');
$n = fpassthru($h);
fclose($h);
echo $n, "\n";
--EXPECT--
aot-passthru
13
