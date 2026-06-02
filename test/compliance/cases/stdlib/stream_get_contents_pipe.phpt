--TEST--
stream_get_contents() — zero-length read on memory stream (#3142)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
echo function_exists('stream_get_contents') ? '1' : '0', "\n";
echo stream_get_contents($f, 0), "|\n";
fclose($f);
--EXPECT--
1
|
