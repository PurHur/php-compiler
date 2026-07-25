--TEST--
stdlib stream_get_contents() offset < -1 keeps current pos JIT (#23190, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcdef');
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 2, -2));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, -1, -5));
echo "\n";
fclose($f);
--EXPECT--
''
'ab'
'abcdef'
