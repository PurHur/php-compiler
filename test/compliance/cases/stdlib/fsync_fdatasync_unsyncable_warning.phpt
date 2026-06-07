--TEST--
stdlib fsync()/fdatasync() warn on unsyncable streams (#7339, ext/standard/file.c)
--FILE--
<?php
$fp = fopen('php://memory', 'w');
fwrite($fp, 'x');
var_export(fsync($fp));
echo "\n";
$fp2 = fopen('php://memory', 'w');
fwrite($fp2, 'x');
var_export(fdatasync($fp2));
echo "\n";
--EXPECT--
PHP Warning:  fsync(): Can't fsync this stream!
PHP Warning:  fdatasync(): Can't fsync this stream!
false
false
