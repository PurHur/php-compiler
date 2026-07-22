<?php
declare(strict_types=1);

// Issue #22285 — ReflectionParameter::getPosition() 0-based index (ext/reflection/php_reflection.c)
function f($a, $b, $c)
{
}

$ps = (new ReflectionFunction('f'))->getParameters();
if (!method_exists($ps[1], 'getPosition')) {
    fwrite(STDERR, "FAIL method_exists getPosition\n");
    exit(1);
}
$got = [$ps[0]->getPosition(), $ps[1]->getPosition(), $ps[2]->getPosition()];
echo implode(',', $got), PHP_EOL;
if ([0, 1, 2] !== $got) {
    fwrite(STDERR, "FAIL function positions\n");
    exit(1);
}

class Demo
{
    public function m($x, $y)
    {
    }
}

$mps = (new ReflectionMethod('Demo', 'm'))->getParameters();
$mgot = [$mps[0]->getPosition(), $mps[1]->getPosition()];
echo 'm_', implode(',', $mgot), PHP_EOL;
if ([0, 1] !== $mgot) {
    fwrite(STDERR, "FAIL method positions\n");
    exit(1);
}

echo "OK\n";
