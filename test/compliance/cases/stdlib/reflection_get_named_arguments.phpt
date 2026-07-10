--TEST--
stdlib ReflectionFunctionAbstract::getNamedArguments() — PHP 8.4 forward profile (#17658, ext/reflection/php_reflection.c)
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsReflectionFunctionGetNamedArguments()) {
    die('skip getNamedArguments() not advertised on PHP 8.2 reference profile (#17658, ext/reflection/php_reflection.c)');
}
--FILE--
<?php
function sample($alpha, $bravo = 1): void {}
echo implode(',', (new ReflectionFunction('sample'))->getNamedArguments()), "\n";

class C {
    public function m($x, $y): void {}
}
echo implode(',', (new ReflectionMethod('C', 'm'))->getNamedArguments()), "\n";
echo implode(',', (new ReflectionFunction('strlen'))->getNamedArguments()), "\n";
--EXPECT--
alpha,bravo
x,y
string
