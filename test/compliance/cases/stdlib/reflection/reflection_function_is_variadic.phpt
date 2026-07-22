--TEST--
ReflectionFunction::isVariadic() (#22045, ext/reflection/php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

function f($a, $b = 1, ...$c) {}
function g($a) {}

$rf = new ReflectionFunction('f');
$rg = new ReflectionFunction('g');
echo $rf->isVariadic() ? "f_yes\n" : "f_no\n";
echo $rg->isVariadic() ? "g_yes\n" : "g_no\n";
echo 'params=', $rf->getNumberOfParameters(), "\n";
echo 'required=', $rf->getNumberOfRequiredParameters(), "\n";

$c = function ($a, ...$b) {};
echo (new ReflectionFunction($c))->isVariadic() ? "closure_yes\n" : "closure_no\n";

class M {
    public function m($a, ...$b) {}
}
echo (new ReflectionMethod(M::class, 'm'))->isVariadic() ? "method_yes\n" : "method_no\n";

echo (new ReflectionFunction('call_user_func'))->isVariadic() ? "cuf_yes\n" : "cuf_no\n";
echo (new ReflectionFunction('strlen'))->isVariadic() ? "strlen_yes\n" : "strlen_no\n";
--EXPECT--
f_yes
g_no
params=3
required=1
closure_yes
method_yes
cuf_yes
strlen_no
