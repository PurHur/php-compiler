--TEST--
stdlib fscanf() sole token — feof() true after consuming last value (#11975, ext/standard/scanf.c)
--FILE--
<?php
$h = fopen('php://memory', 'r+');
fwrite($h, 'hello');
rewind($h);
$r = fscanf($h, '%s');
echo feof($h) ? 'ok' : 'fail';
echo ' val=';
var_export($r[0] ?? null);
fclose($h);
--EXPECT--
ok val='hello'
