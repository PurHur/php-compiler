<?php

// Repro #19616 — method_exists(Closure, "__invoke") must be true (php-src ext/standard/class.c).
$c = function () {};
var_export(method_exists($c, '__invoke'));
echo PHP_EOL;
var_export(method_exists(Closure::class, '__invoke'));
echo PHP_EOL;
class Invokable
{
    public function __invoke()
    {
    }
}
var_export(method_exists(new Invokable(), '__invoke'));
echo PHP_EOL;
