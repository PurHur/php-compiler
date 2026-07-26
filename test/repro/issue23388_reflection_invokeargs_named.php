<?php
/**
 * #23388 — ReflectionFunction/Method::invokeArgs() maps string keys to parameter names
 * (ext/reflection/php_reflection.c / zend_call_function).
 */
function f($x, $y)
{
    return "$x|$y";
}
$rf = new ReflectionFunction('f');
echo 'fn: '.$rf->invokeArgs(['y' => 2, 'x' => 1])."\n";
echo 'pos: '.$rf->invokeArgs([9, 8])."\n";

function g($x = 1, $y = 2)
{
    return "$x|$y";
}
echo 'def: '.(new ReflectionFunction('g'))->invokeArgs(['y' => 9])."\n";

class A
{
    public function m($x, $y)
    {
        return "$x|$y";
    }

    public static function s($x, $y)
    {
        return "$x|$y";
    }
}
echo 'm: '.(new ReflectionMethod(A::class, 'm'))->invokeArgs(new A(), ['y' => 2, 'x' => 1])."\n";
echo 's: '.(new ReflectionMethod(A::class, 's'))->invokeArgs(null, ['y' => 2, 'x' => 1])."\n";
