<?php

// Issue #12905 — ReflectionFunction::getClosure() (ext/reflection/php_reflection.c).
function rf12905_answer(): int
{
    return 42;
}

$rf = new ReflectionFunction('rf12905_answer');
echo 'method_exists=', var_export(method_exists($rf, 'getClosure'), true), "\n";
$c = $rf->getClosure();
echo 'invoke=', $c(), "\n";

$fromCallable = ReflectionFunction::createFromCallable(
    Closure::fromCallable('rf12905_answer')
);
$equiv = $fromCallable->getClosure();
echo 'from_callable=', $equiv(), "\n";
