--TEST--
ReflectionFunction::getClosureScopeClass() on fromCallable instance method (#11250, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public function m() {}
}
$c = Closure::fromCallable([new C(), 'm']);
$scope = (new ReflectionFunction($c))->getClosureScopeClass();
echo $scope instanceof ReflectionClass ? $scope->getName() : 'null', "\n";
$f = function () { return 1; };
var_export((new ReflectionFunction($f))->getClosureScopeClass());
echo "\n";
?>
--EXPECT--
C
NULL
