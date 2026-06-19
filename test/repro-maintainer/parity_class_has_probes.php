<?php
declare(strict_types=1);

class C { public function m(): void {} }
class D { public int $p = 1; }
class E { public const X = 1; }

var_export(class_has_method(C::class, 'm'));
echo "\n";
var_export(class_has_property(D::class, 'p'));
echo "\n";
var_export(class_has_constant(E::class, 'X'));
echo "\n";
