--TEST--
stdlib stream_get_contents() length < -1 throws ValueError (#24560, ext/standard/file.c)
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
var_export(stream_get_contents($f, null));
echo "\n";
rewind($f);
try {
    var_export(stream_get_contents($f, -2));
    echo "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
$len = -3;
$f2 = fopen('php://memory', 'r+');
fwrite($f2, 'xy');
rewind($f2);
try {
    var_export(stream_get_contents($f2, $len));
    echo "\n";
} catch (ValueError $e) {
    echo 'ValueError: ', $e->getMessage(), "\n";
}
fclose($f);
fclose($f2);
--EXPECT--
'abcd'
''
'abcd'
ValueError: stream_get_contents(): Argument #2 ($length) must be greater than or equal to -1
ValueError: stream_get_contents(): Argument #2 ($length) must be greater than or equal to -1
