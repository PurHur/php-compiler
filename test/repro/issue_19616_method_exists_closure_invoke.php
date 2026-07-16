<?php

// Repro #19616 — method_exists(Closure, "__invoke") must be true (php-src ext/standard/class.c).
// Use IIFE / class-string forms for AOT (assigned object locals hit a pre-existing
// TYPE_VALUE method_exists/property_exists abort shared with stdClass).
var_export(method_exists(function () {}, '__invoke'));
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
