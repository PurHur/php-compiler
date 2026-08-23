<?php

class A
{
    public const K = 2;
    protected const P = 'p';
}

class B extends A
{
    public const OWN = 9;
}

$ra = new ReflectionClass(A::class);
$rb = new ReflectionClass(B::class);

var_dump($ra->getConstant('K'));
var_dump($ra->getConstant('missing'));
var_dump($rb->getConstant('K'));
var_dump($rb->getConstant('OWN'));
var_dump($rb->getConstant('P'));
