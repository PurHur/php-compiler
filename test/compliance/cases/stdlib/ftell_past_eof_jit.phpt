--TEST--
JIT: ftell() — past EOF on empty php://memory returns false (#11712, ext/standard/streams.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fseek($h, 99);
echo ftell($h) === false ? 'false' : 'pos', "\n";
fclose($h);
--EXPECT--
false
