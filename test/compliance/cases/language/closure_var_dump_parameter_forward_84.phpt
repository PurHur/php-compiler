--TEST--
Closure var_dump parameter + name/file/line on PROFILE=8.4 (#24521, #22565)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
$c = function (int $x, string $y = 'a'): int { return $x; };
var_dump($c);
--EXPECTF--
object(Closure)#%d (4) {
  ["name"]=>
  string(9) "{closure}"
  ["file"]=>
  string(%d) "%s"
  ["line"]=>
  int(%d)
  ["parameter"]=>
  array(2) {
    ["$x"]=>
    string(10) "<required>"
    ["$y"]=>
    string(10) "<optional>"
  }
}
