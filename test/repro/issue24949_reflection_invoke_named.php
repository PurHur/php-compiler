<?php
/**
 * #24949 — ReflectionFunction/Method::invoke() forwards named args to the callee
 * (ext/reflection/php_reflection.c / zend_call_function).
 */
function f($a = 1, $b = 2)
{
    echo "$a|$b\n";
}
(new ReflectionFunction('f'))->invoke(b: 9);
(new ReflectionFunction('f'))->invoke(10, b: 20);

class C
{
    public function m($a = 1, $b = 2)
    {
        echo "$a|$b\n";
    }
}
$obj = new C();
(new ReflectionMethod(C::class, 'm'))->invoke($obj, b: 9);
(new ReflectionMethod(C::class, 'm'))->invoke($obj, 7, b: 8);
