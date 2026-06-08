--TEST--
Stdlib: Closure::__debugInfo() — var_dump shows name/file/line (#7069)
--FILE--
<?php
declare(strict_types=1);
$c = function () { return 1; };
var_dump($c);
--EXPECTF--
object(Closure)#%d (3) {
  ["name"]=>
  string(9) "{closure}"
  ["file"]=>
  string(1) "-"
  ["line"]=>
  int(%d)
}
