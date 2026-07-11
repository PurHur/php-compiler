<?php

function f(): never
{
    throw new Exception('x');
}

$r = new ReflectionFunction('f');
var_export($r->getReturnType()->getName());
echo "\n";
