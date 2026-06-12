<?php

$f = function () {
    return 1;
};
$r = new ReflectionFunction($f);
var_export($r->getClosureScopeClass());
echo "\n";
var_export($r->isClosure());
echo "\n";

$x = 42;
$g = function () use ($x) {
    return $x;
};
$used = (new ReflectionFunction($g))->getClosureUsedVariables();
var_export($used);
echo "\n";
var_export($used['x']);
echo "\n";

class Rf6649Scope {
    public static function make(): Closure
    {
        return function () {
            return 2;
        };
    }
}

$scope = (new ReflectionFunction(Rf6649Scope::make()))->getClosureScopeClass();
echo $scope instanceof ReflectionClass ? "scope-ok\n" : "scope-bad\n";

$named = new ReflectionFunction('strlen');
var_export($named->isClosure());
echo "\n";
var_export($named->getClosureUsedVariables());
echo "\n";
