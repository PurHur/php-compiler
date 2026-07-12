--TEST--
ReflectionFunction::isGenerator() / ReflectionMethod::isGenerator() (#17505, ext/reflection/php_reflection.c)
--FILE--
<?php
function gen_fn() { yield 1; }
function plain_fn() { return 1; }

echo var_export((new ReflectionFunction('gen_fn'))->isGenerator(), true), "\n";
echo var_export((new ReflectionFunction('plain_fn'))->isGenerator(), true), "\n";
echo var_export((new ReflectionFunction('strlen'))->isGenerator(), true), "\n";

class GenClass {
    public function genMethod() { yield 2; }
    public function plainMethod() { return 2; }
}

echo var_export((new ReflectionMethod('GenClass', 'genMethod'))->isGenerator(), true), "\n";
echo var_export((new ReflectionMethod('GenClass', 'plainMethod'))->isGenerator(), true), "\n";

$closure = function () { yield 3; };
echo var_export((new ReflectionFunction($closure))->isGenerator(), true), "\n";

$plainClosure = function () { return 3; };
echo var_export((new ReflectionFunction($plainClosure))->isGenerator(), true), "\n";
?>
--EXPECT--
true
false
false
true
false
true
false
