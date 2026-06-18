--TEST--
fwrite(): negative length writes zero bytes on JIT (#9348)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
echo fwrite($h, 'x', -1), "\n";
fclose($h);
--EXPECT--
0
