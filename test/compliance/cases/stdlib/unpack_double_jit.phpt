--TEST--
stdlib unpack() double format code d JIT (issue #4662)
--FILE--
<?php
$packed = pack('d', 1.5);
$r = unpack('d', $packed);
var_dump($r);
echo gettype($r[1]), "\n";
--EXPECT--
array(1) {
  [1]=>
  float(1.5)
}
double
