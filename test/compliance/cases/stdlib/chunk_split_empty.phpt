--TEST--
stdlib chunk_split() empty string still appends default ending (#11055)
--FILE--
<?php
var_export(chunk_split(''));
echo "\n";
var_export(chunk_split('', 1, ''));
echo "\n";
--EXPECT--
"\r\n"
''
