<?php

declare(strict_types=1);

class C11464 {
    /** @var int */
    public int $x = 1;
    public int $y = 2;
}

$with = new ReflectionProperty(C11464::class, 'x');
$without = new ReflectionProperty(C11464::class, 'y');

echo 'method_exists=', (int) method_exists($with, 'getDocComment'), "\n";
$doc = $with->getDocComment();
echo 'with_doc=', (int) (is_string($doc) && str_contains($doc, '@var')), "\n";
echo 'without_doc=', var_export($without->getDocComment(), true), "\n";
