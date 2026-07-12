<?php

declare(strict_types=1);

// #18297 — ReflectionClass::isFinal()/isIterateable() (ext/reflection/php_reflection.c)

var_export((new ReflectionClass('Closure'))->isFinal());
echo "\n";
var_export((new ReflectionClass('Generator'))->isFinal());
echo "\n";
var_export((new ReflectionClass('stdClass'))->isFinal());
echo "\n";
var_export((new ReflectionClass('ArrayObject'))->isIterateable());
echo "\n";
