--TEST--
JIT stream_get_contents() length < -1 throws ValueError (#24560, ext/standard/file.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fwrite($f, 'abcd');
rewind($f);
var_export(stream_get_contents($f, -1));
echo "\n";
rewind($f);
var_export(stream_get_contents($f, 0));
echo "\n";
rewind($f);
try {
    var_export(stream_get_contents($f, -2));
    echo "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
fclose($f);
--EXPECT--
'abcd'
''
ValueError: stream_get_contents(): Argument #2 ($length) must be greater than or equal to -1
