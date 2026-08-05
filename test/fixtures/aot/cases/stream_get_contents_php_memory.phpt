--TEST--
AOT: stream_get_contents php://memory after fwrite+rewind (#27437)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'hi');
rewind($f);
var_export(stream_get_contents($f));
echo "\n";
--EXPECT--
'hi'
