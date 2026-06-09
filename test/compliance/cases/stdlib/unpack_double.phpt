--TEST--
stdlib unpack() double format code d (issue #4662)
--FILE--
<?php
$packed = pack('d', 1.5);
$r = unpack('d', $packed);
var_dump($r);
echo gettype($r[1]), "\n";
$r = unpack('f', pack('f', 2.25));
var_dump($r[1]);
--EXPECT--
array(1) {
  [1]=>
  float(1.5)
}
double
float(2.25)
