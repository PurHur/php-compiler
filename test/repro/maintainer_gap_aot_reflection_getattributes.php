<?php
// Repro for #26828 — ReflectionClass::getAttributes under AOT (php-src-strict).
#[Attribute]
class A {}
#[A]
class B {}
$r = new ReflectionClass(B::class);
$attrs = $r->getAttributes();
echo count($attrs), ' ', $attrs[0]->getName(), "\n";
