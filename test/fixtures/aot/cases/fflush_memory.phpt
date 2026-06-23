--TEST--
AOT: fflush() on php://memory after fwrite
--FILE--
<?php
$h = fopen('php://memory', 'w+');
fwrite($h, 'abc');
echo fflush($h) ? '1' : '0', "\n";
fclose($h);
--EXPECT--
1
