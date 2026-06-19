<?php

declare(strict_types=1);

/**
 * Repro for #9988 — enum case in attribute constructor argument (zend_compile.c).
 */

enum E: int
{
    case A = 1;
}

#[SomeAttr(E::A)]
class C
{
}

class SomeAttr
{
    public function __construct(public mixed $v)
    {
    }
}

$rc = new ReflectionClass(C::class);
$args = $rc->getAttributes()[0]->getArguments();
var_export($args[0]);
echo "\n";
