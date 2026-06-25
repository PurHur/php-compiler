--TEST--
JIT: fgetc() — EOF on empty php://memory returns false (#11711, ext/standard/streams.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
echo fgetc($h) === false ? 'false' : 'byte', "\n";
echo feof($h) ? 'eof' : 'no', "\n";
fclose($h);
--EXPECT--
false
eof
