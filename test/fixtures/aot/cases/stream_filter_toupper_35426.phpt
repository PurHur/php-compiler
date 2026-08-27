--TEST--
AOT stream_filter_append string.toupper / rot13 (#35426)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'Hello');
rewind($f);
stream_filter_append($f, 'string.toupper');
echo stream_get_contents($f), "\n";
$g = fopen('php://temp', 'r+');
fwrite($g, 'abc');
rewind($g);
stream_filter_append($g, 'string.rot13');
echo stream_get_contents($g), "\n";
--EXPECT--
HELLO
nop
