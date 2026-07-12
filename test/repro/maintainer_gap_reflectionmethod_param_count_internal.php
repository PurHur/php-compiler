<?php

declare(strict_types=1);

// #18338 — ReflectionMethod internal ctor signatures (ext/reflection/php_reflection.c)

echo (new ReflectionMethod('ArrayObject', '__construct'))->getNumberOfParameters(), "\n";
echo (new ReflectionMethod('ArrayObject', '__construct'))->getNumberOfRequiredParameters(), "\n";
echo (new ReflectionParameter(['ArrayObject', '__construct'], 0))->getName(), "\n";
var_export((new ReflectionParameter(['ArrayObject', '__construct'], 0))->isOptional());
echo "\n";
