<?php

declare(strict_types=1);

function gen_fn(): Generator
{
    yield 1;
}

function plain_fn(): int
{
    return 1;
}

class GenClass
{
    public function genMethod(): Generator
    {
        yield 2;
    }

    public function plainMethod(): int
    {
        return 2;
    }
}

$genClosure = function () { yield 3; };
$plainClosure = function () { return 3; };

$checks = [
    (new ReflectionFunction('gen_fn'))->isGenerator(),
    !(new ReflectionFunction('plain_fn'))->isGenerator(),
    !(new ReflectionFunction('strlen'))->isGenerator(),
    (new ReflectionMethod('GenClass', 'genMethod'))->isGenerator(),
    !(new ReflectionMethod('GenClass', 'plainMethod'))->isGenerator(),
    (new ReflectionFunction($genClosure))->isGenerator(),
    !(new ReflectionFunction($plainClosure))->isGenerator(),
];

$ok = true;
foreach ($checks as $check) {
    if (!$check) {
        $ok = false;
        break;
    }
}
echo $ok ? "ok\n" : "fail\n";
