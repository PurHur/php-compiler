--TEST--
stdlib fstat() on php://memory and php://temp — mode 100666 octal (#18402, ext/standard/streams.c)
--FILE--
<?php
$m = fopen('php://memory', 'r+');
$s = fstat($m);
echo decoct($s[2] & 0777777), "\n";
fclose($m);

$m = fopen('php://temp', 'r+');
$s = fstat($m);
echo decoct($s[2] & 0777777), "\n";
fclose($m);
--EXPECT--
100666
100666
