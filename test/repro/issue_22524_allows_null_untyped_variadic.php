<?php
// Issue #22524 — ReflectionParameter::allowsNull() for untyped variadic (php-src-strict)
function f(...$c) {}
$p = (new ReflectionFunction('f'))->getParameters()[0];
echo 'hasType=', var_export($p->hasType(), true), "\n";
echo 'variadic=', var_export($p->isVariadic(), true), "\n";
echo 'allowsNull=', var_export($p->allowsNull(), true), "\n";
