<?php

declare(strict_types=1);

$rf = new ReflectionFunction('strlen');
echo $rf->getExtension()?->getName(), "\n";

$closure = new ReflectionFunction(fn () => 1);
echo $closure->getExtension() === null ? 'null' : 'not-null', "\n";
