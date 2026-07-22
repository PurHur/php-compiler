<?php
declare(strict_types=1);

// Issue #22145 — ReflectionParameter::canBePassedByValue() for by-ref (#18073 sibling)
function f(&$a, $b, &...$c)
{
}

$want = [
    'a' => ['byRef' => '1', 'byValue' => '0'],
    'b' => ['byRef' => '0', 'byValue' => '1'],
    'c' => ['byRef' => '1', 'byValue' => '0'],
];

foreach ((new ReflectionFunction('f'))->getParameters() as $p) {
    $name = $p->getName();
    $byRef = $p->isPassedByReference() ? '1' : '0';
    $byValue = $p->canBePassedByValue() ? '1' : '0';
    echo $name, ' byRef=', $byRef, ' byValue=', $byValue, "\n";
    if ($byRef !== $want[$name]['byRef'] || $byValue !== $want[$name]['byValue']) {
        fwrite(STDERR, "FAIL $name\n");
        exit(1);
    }
}

class Demo
{
    public function m(Foo &$obj, int &$n, $plain)
    {
    }
}
class Foo
{
}

$rm = new ReflectionMethod(Demo::class, 'm');
foreach ($rm->getParameters() as $p) {
    $byRef = $p->isPassedByReference() ? '1' : '0';
    $byValue = $p->canBePassedByValue() ? '1' : '0';
    echo 'm_', $p->getName(), ' byRef=', $byRef, ' byValue=', $byValue, "\n";
    if ($p->isPassedByReference() && $p->canBePassedByValue()) {
        fwrite(STDERR, 'FAIL typed by-ref still byValue true: '.$p->getName()."\n");
        exit(1);
    }
    if (!$p->isPassedByReference() && !$p->canBePassedByValue()) {
        fwrite(STDERR, 'FAIL by-value param byValue false: '.$p->getName()."\n");
        exit(1);
    }
}

echo "OK\n";
