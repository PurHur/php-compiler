--TEST--
stdlib settype() to object — stdClass scalar property (ext/standard/type.c, #4254)
--FILE--
<?php
$x = 1;
settype($x, 'object');
var_dump($x);

$y = 3.5;
settype($y, 'object');
var_dump($y);

$s = 'hi';
settype($s, 'object');
var_dump($s);

$b = true;
settype($b, 'object');
var_dump($b);

$n = null;
settype($n, 'object');
var_dump($n);
--EXPECTF--
object(stdClass)#%d (1) {
  ["scalar"]=>
  int(1)
}
object(stdClass)#%d (1) {
  ["scalar"]=>
  float(3.5)
}
object(stdClass)#%d (1) {
  ["scalar"]=>
  string(2) "hi"
}
object(stdClass)#%d (1) {
  ["scalar"]=>
  bool(true)
}
object(stdClass)#%d (0) {
}
