--TEST--
fwrite(): negative length writes zero bytes (ext/standard/streams.c, #9348)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
echo fwrite($h, 'x', -1), "\n";
echo fwrite($h, 'hello', 2), "\n";
echo fwrite($h, 'world'), "\n";
rewind($h);
echo stream_get_contents($h), "\n";
fclose($h);
--EXPECT--
0
2
5
heworld
