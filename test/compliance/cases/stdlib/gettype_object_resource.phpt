--TEST--
stdlib gettype() — object and stream resource (#3618)
--FILE--
<?php
class A {}
echo gettype(new A()), "\n";
echo gettype(new stdClass()), "\n";
$r = fopen('php://memory', 'r');
echo gettype($r), "\n";
echo gettype(42), "\n";
fclose($r);
echo gettype($r), "\n";
--EXPECT--
object
object
resource
integer
resource
