--TEST--
ReflectionMethod::$class is declaring class for inherited + trait methods (#18298, #22582)
--FILE--
<?php
$m = new ReflectionMethod('ArrayObject', 'offsetExists');
echo $m->class, "\n";
echo $m->name, "\n";
echo $m->getDeclaringClass()->getName(), "\n";

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

$via = (new ReflectionClass(Child::class))->getMethod('m');
echo 'getMethod class=', $via->class, ' decl=', $via->getDeclaringClass()->getName(), "\n";

foreach ((new ReflectionClass(Child::class))->getMethods() as $x) {
    if ($x->name === 'm') {
        echo 'getMethods class=', $x->class, ' decl=', $x->getDeclaringClass()->getName(), "\n";
    }
}

trait T
{
    public function t()
    {
    }
}
class C
{
    use T;
}
class D extends C
{
}

$rt = new ReflectionMethod(D::class, 't');
echo 'trait class=', $rt->class, ' decl=', $rt->getDeclaringClass()->getName(), "\n";
--EXPECT--
ArrayObject
offsetExists
ArrayObject
inst class=Base decl=Base
static class=Base decl=Base
getMethod class=Base decl=Base
getMethods class=Base decl=Base
trait class=C decl=C
