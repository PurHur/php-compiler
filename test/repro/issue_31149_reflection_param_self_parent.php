<?php
class P { const X = 3; }
class C extends P {
    const Y = 4;
    function f($a = self::Y, $b = parent::X, $c = C::Y, $d = \C::Y) {}
}
foreach ((new ReflectionMethod('C', 'f'))->getParameters() as $p) {
    echo $p->getName(), '=', $p->getDefaultValueConstantName(),
        ' val=', var_export($p->getDefaultValue(), true),
        ' const=', $p->isDefaultValueConstant() ? '1' : '0', "\n";
}
class T {
    const X = 1;
    function m($a = Self::X) {}
}
echo 'Self=', (new ReflectionMethod('T', 'm'))->getParameters()[0]->getDefaultValueConstantName(), "\n";
trait Tr {
    const Z = 9;
    function t($a = self::Z) {}
}
class U { use Tr; }
echo 'trait=', (new ReflectionMethod('U', 't'))->getParameters()[0]->getDefaultValueConstantName(), "\n";
class Other { const N = 7; }
class Named {
    function n($a = Other::N) {}
}
echo 'named=', (new ReflectionMethod('Named', 'n'))->getParameters()[0]->getDefaultValueConstantName(), "\n";
