--TEST--
JIT: gettype() object and stream resource (#3618)
--JIT--
--FILE--
<?php
class A {}
echo gettype(new A()), "\n";
$r = fopen('php://memory', 'r');
echo gettype($r), "\n";
echo gettype(42), "\n";
fclose($r);
--EXPECT--
object
resource
integer
