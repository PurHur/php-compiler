<?php
// Compile-only (#9989): class_has_* lowers for native AOT.
declare(strict_types=1);

class C { public function m(): void {} }
class D { public int $p = 1; }
class E { public const X = 1; }

echo (function_exists('class_has_method') ? '1' : '0');
echo (class_has_method(C::class, 'm') ? '1' : '0');
echo (class_has_property(D::class, 'p') ? '1' : '0');
echo (class_has_constant(E::class, 'X') ? '1' : '0');
echo "\n";
