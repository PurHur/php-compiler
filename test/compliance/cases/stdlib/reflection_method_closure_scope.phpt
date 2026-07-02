--TEST--
ReflectionMethod::getClosureScopeClass()/getClosureThis() on plain methods (#14614, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public function m() {}
}
$rm = new ReflectionMethod(C::class, 'm');
echo method_exists($rm, 'getClosureScopeClass') ? "scope_method\n" : "scope_missing\n";
echo method_exists($rm, 'getClosureThis') ? "this_method\n" : "this_missing\n";
var_export($rm->getClosureScopeClass());
echo "\n";
var_export($rm->getClosureThis());
echo "\n";
?>
--EXPECT--
scope_method
this_method
NULL
NULL
