--TEST--
Closure var_dump parameter bag — Zend $name => <required>/<optional> (#24521, Zend/zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
$required = function (int $x): int { return $x; };
var_dump($required);

$optional = function (int $x = 1, string $y = 'a') { return $x; };
var_dump($optional);

$byRef = function (int &$x) { $x++; };
var_dump($byRef);

$variadic = function (int $a, ...$rest) { return $a; };
var_dump($variadic);

$empty = function () { return 1; };
var_dump($empty);
--EXPECTF--
object(Closure)#%d (1) {
  ["parameter"]=>
  array(1) {
    ["$x"]=>
    string(10) "<required>"
  }
}
object(Closure)#%d (1) {
  ["parameter"]=>
  array(2) {
    ["$x"]=>
    string(10) "<optional>"
    ["$y"]=>
    string(10) "<optional>"
  }
}
object(Closure)#%d (1) {
  ["parameter"]=>
  array(1) {
    ["&$x"]=>
    string(10) "<required>"
  }
}
object(Closure)#%d (1) {
  ["parameter"]=>
  array(2) {
    ["$a"]=>
    string(10) "<required>"
    ["$rest"]=>
    string(10) "<optional>"
  }
}
object(Closure)#%d (0) {
}
