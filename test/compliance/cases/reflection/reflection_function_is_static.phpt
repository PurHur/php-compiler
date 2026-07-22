--TEST--
ReflectionFunction::isStatic() for closures and plain functions (#22024, ext/reflection/php_reflection.c)
--FILE--
<?php
$f = function () {};
$s = static function () {};
echo var_export((new ReflectionFunction($f))->isStatic(), true), "\n";
echo var_export((new ReflectionFunction($s))->isStatic(), true), "\n";
function plain_rf_is_static() {}
echo var_export((new ReflectionFunction('plain_rf_is_static'))->isStatic(), true), "\n";
echo var_export((new ReflectionFunction('strlen'))->isStatic(), true), "\n";
?>
--EXPECT--
false
true
false
false
