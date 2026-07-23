<?php
// Issue #22488 — ReflectionFunction::$name (+ Parameter::$name) php-src-strict
function foo(int $a): void
{
}

$rf = new ReflectionFunction('foo');
$rp = new ReflectionParameter('foo', 'a');
echo $rf->getName(), "\n";
var_export($rf->name);
echo "\n";
var_export($rp->name);
echo "\n";
echo 'pe_name=', property_exists($rf, 'name') ? '1' : '0', "\n";
echo 'pe_funcName=', property_exists($rf, 'funcName') ? '1' : '0', "\n";
echo 'eq=', ($rf->name === $rf->getName()) ? '1' : '0', "\n";
var_dump($rf);
