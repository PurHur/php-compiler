--TEST--
feof() on fresh php://memory is false until read past EOF (#9283)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
echo feof($h) ? '1' : '0', "\n";
fwrite($h, 'x');
rewind($h);
echo feof($h) ? '1' : '0', "\n";
fgetc($h);
echo feof($h) ? '1' : '0', "\n";
fclose($h);
--EXPECT--
0
0
0
