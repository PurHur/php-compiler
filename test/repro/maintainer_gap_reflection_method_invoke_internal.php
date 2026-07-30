<?php

declare(strict_types=1);

// #25294 — ReflectionMethod::invoke/invokeArgs on internal methods (ext/reflection/php_reflection.c)

$d = new DateTime('2020-01-15');
$rm = new ReflectionMethod(DateTime::class, 'format');
echo $rm->invoke($d, 'Y'), "\n";
echo $rm->invokeArgs($d, ['Y']), "\n";
