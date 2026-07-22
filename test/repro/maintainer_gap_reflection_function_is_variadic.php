<?php
/**
 * #22045 — ReflectionFunction::isVariadic() (php-src zim_reflection_function_abstract_isVariadic).
 */
function f($a, $b = 1, ...$c)
{
}

function g($a)
{
}

$rf = new ReflectionFunction('f');
$rg = new ReflectionFunction('g');
echo 'f_variadic=', $rf->isVariadic() ? 'true' : 'false', "\n";
echo 'g_variadic=', $rg->isVariadic() ? 'true' : 'false', "\n";
echo 'f_params=', $rf->getNumberOfParameters(), "\n";
echo 'f_required=', $rf->getNumberOfRequiredParameters(), "\n";

$c = function ($a, ...$b) {
};
echo 'closure_variadic=', (new ReflectionFunction($c))->isVariadic() ? 'true' : 'false', "\n";

class M
{
    public function m($a, ...$b)
    {
    }
}
echo 'method_variadic=', (new ReflectionMethod(M::class, 'm'))->isVariadic() ? 'true' : 'false', "\n";

echo 'call_user_func=', (new ReflectionFunction('call_user_func'))->isVariadic() ? 'true' : 'false', "\n";
echo 'strlen=', (new ReflectionFunction('strlen'))->isVariadic() ? 'true' : 'false', "\n";
