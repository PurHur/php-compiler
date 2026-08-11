--TEST--
Closure var_dump name/file/line via get_debug_info handler on PROFILE=8.4 (#22565, #7069)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
$c = function () { return 1; };
echo 'method_exists=', method_exists($c, '__debugInfo') ? '1' : '0', "\n";
var_dump($c);
--EXPECTF--
method_exists=0
object(Closure)#%d (3) {
  ["name"]=>
  string(%d) "{closure:%s:%d}"
  ["file"]=>
  string(%d) "%s"
  ["line"]=>
  int(%d)
}
