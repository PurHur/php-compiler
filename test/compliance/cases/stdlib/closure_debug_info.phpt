--TEST--
Closure var_dump — handler dump; no __debugInfo method on reference profile (#22565, re-#7069)
--FILE--
<?php
declare(strict_types=1);
$c = function () { return 1; };
echo 'method_exists=', method_exists($c, '__debugInfo') ? '1' : '0', "\n";
var_dump($c);
--EXPECTF--
method_exists=0
object(Closure)#%d (0) {
}
