<?php

declare(strict_types=1);

// #18335 — ReflectionClass::isInterface()/isTrait()/getModifiers() (ext/reflection/php_reflection.c)

trait ProbeTrait {}

var_export((new ReflectionClass('Iterator'))->isInterface());
echo "\n";
var_export((new ReflectionClass('Stringable'))->isTrait());
echo "\n";
var_export((new ReflectionClass('ProbeTrait'))->isTrait());
echo "\n";
var_export((new ReflectionClass('stdClass'))->getModifiers());
echo "\n";
