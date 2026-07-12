<?php

declare(strict_types=1);

// #18335 — ReflectionClass::isInterface()/isTrait()/getModifiers() (ext/reflection/php_reflection.c)

var_export((new ReflectionClass('Iterator'))->isInterface());
echo "\n";
var_export((new ReflectionClass('Stringable'))->isTrait());
echo "\n";
trait ProbeTrait {}
var_export((new ReflectionClass('ProbeTrait'))->isTrait());
echo "\n";
echo (new ReflectionClass('stdClass'))->getModifiers(), "\n";
