--TEST--
AOT: gettype() object and stream resource (#3618)
--FILE--
<?php
class A {}
echo gettype(new A()), "\n";
echo gettype(new stdClass()), "\n";
$path = sys_get_temp_dir() . '/phpc_aot_gettype.txt';
file_put_contents($path, 'x');
$r = fopen($path, 'r');
echo gettype($r), "\n";
echo gettype(42), "\n";
fclose($r);
@unlink($path);
--EXPECT--
object
object
resource
integer
