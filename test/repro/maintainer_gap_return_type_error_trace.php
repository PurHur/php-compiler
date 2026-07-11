<?php

declare(strict_types=1);

function g(): int
{
    return 'x';
}

class C
{
    public function m(): string
    {
        return 42;
    }
}

function gen(): Generator
{
    yield 1;
    return 'x';
}

$ok = true;

try {
    g();
} catch (TypeError $e) {
    $trace = $e->getTrace();
    if ([] === $trace || ($trace[0]['function'] ?? '') !== 'g') {
        $ok = false;
        echo 'fail function trace: '.json_encode($trace)."\n";
    }
}

try {
    (new C())->m();
} catch (TypeError $e) {
    $trace = $e->getTrace();
    if ([] === $trace || ($trace[0]['function'] ?? '') !== 'm') {
        $ok = false;
        echo 'fail method trace: '.json_encode($trace)."\n";
    }
}

$generator = gen();
try {
    $generator->next();
    $generator->next();
} catch (TypeError $e) {
    $trace = $e->getTrace();
    if ([] === $trace) {
        $ok = false;
        echo 'fail generator trace: '.json_encode($trace)."\n";
    }
}

echo $ok ? "ok\n" : "fail\n";
