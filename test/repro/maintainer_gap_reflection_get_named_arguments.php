<?php

declare(strict_types=1);

if (!method_exists(ReflectionFunction::class, 'getNamedArguments')) {
    fwrite(STDERR, "fail: getNamedArguments missing\n");
    exit(1);
}

function sample($alpha, $bravo = 1): void
{
}

$names = (new ReflectionFunction('sample'))->getNamedArguments();
if ($names !== ['alpha', 'bravo']) {
    fwrite(STDERR, 'fail function: '.var_export($names, true)."\n");
    exit(1);
}

class SampleMethodHost
{
    public function m($x, $y): void
    {
    }
}

$methodNames = (new ReflectionMethod(SampleMethodHost::class, 'm'))->getNamedArguments();
if ($methodNames !== ['x', 'y']) {
    fwrite(STDERR, 'fail method: '.var_export($methodNames, true)."\n");
    exit(1);
}

$internal = (new ReflectionFunction('strlen'))->getNamedArguments();
if ($internal !== ['string']) {
    fwrite(STDERR, 'fail internal: '.var_export($internal, true)."\n");
    exit(1);
}

echo "ok\n";
