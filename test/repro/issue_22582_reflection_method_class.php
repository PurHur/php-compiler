<?php
// Issue #22582 — ReflectionMethod::$class must match getDeclaringClass() (declaring scope).
class Base
{
    public function m()
    {
    }

    public static function s()
    {
    }
}
class Child extends Base
{
}

$rm = new ReflectionMethod(Child::class, 'm');
echo 'inst class=', $rm->class, ' decl=', $rm->getDeclaringClass()->getName(), "\n";
$rs = new ReflectionMethod(Child::class, 's');
echo 'static class=', $rs->class, ' decl=', $rs->getDeclaringClass()->getName(), "\n";
