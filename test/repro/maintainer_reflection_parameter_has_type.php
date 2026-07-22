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

foreach ((new ReflectionMethod(Demo::class, 'm'))->getParameters() as $p) {
    echo 'm_', $p->getName(), ' hasType=', $p->hasType() ? '1' : '0', "\n";
}
