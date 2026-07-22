--TEST--
ReflectionParameter::canBePassedByValue() false for by-ref params (#22145)
--FILE--
<?php
declare(strict_types=1);

function f(&$a, $b, &...$c)
{
}

foreach ((new ReflectionFunction('f'))->getParameters() as $p) {
    echo $p->getName(),
        ' byRef=', $p->isPassedByReference() ? '1' : '0',
        ' byValue=', $p->canBePassedByValue() ? '1' : '0',
        "\n";
}

class Foo
{
}

class Demo
{
    public function m(Foo &$obj, $plain)
    {
    }
}

foreach ((new ReflectionMethod('Demo', 'm'))->getParameters() as $p) {
    echo 'm_', $p->getName(),
        ' byRef=', $p->isPassedByReference() ? '1' : '0',
        ' byValue=', $p->canBePassedByValue() ? '1' : '0',
        "\n";
}
?>
--EXPECT--
a byRef=1 byValue=0
b byRef=0 byValue=1
c byRef=1 byValue=0
m_obj byRef=1 byValue=0
m_plain byRef=0 byValue=1
