--TEST--
ReflectionParameter::hasType() false for untyped params (#22064)
--FILE--
<?php
declare(strict_types=1);

function f(int $a, $b, ?string $c = null, mixed $d = null)
{
}

foreach ((new ReflectionFunction('f'))->getParameters() as $p) {
    echo $p->getName(), ' hasType=', $p->hasType() ? '1' : '0',
        ' type=', $p->hasType() ? (string) $p->getType() : '-', "\n";
}

class Demo
{
    public function m(array $x, $y)
    {
    }
}

foreach ((new ReflectionMethod('Demo', 'm'))->getParameters() as $p) {
    echo 'm_', $p->getName(), ' hasType=', $p->hasType() ? '1' : '0', "\n";
}
?>
--EXPECT--
a hasType=1 type=int
b hasType=0 type=-
c hasType=1 type=?string
d hasType=1 type=mixed
m_x hasType=1
m_y hasType=0
