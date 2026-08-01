<?php

/**
 * Repro #26627 — attribute ctor ::class folds like Zend (self/parent/Named).
 */
#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
class Attr
{
    public function __construct(public string $x)
    {
    }
}

class B
{
}

class A extends B
{
    #[Attr(self::class)]
    #[Attr(parent::class)]
    #[Attr(A::class)]
    public function f()
    {
    }
}

$attrs = (new ReflectionMethod('A', 'f'))->getAttributes();
echo $attrs[0]->newInstance()->x, "\n";
echo $attrs[1]->newInstance()->x, "\n";
echo $attrs[2]->newInstance()->x, "\n";
