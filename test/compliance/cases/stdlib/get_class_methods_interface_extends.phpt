--TEST--
stdlib get_class_methods() — extended interface includes parent methods (#11689, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
interface I { public function a(): void; }
interface J extends I { public function b(): void; }
$m = get_class_methods(J::class);
sort($m);
echo implode(',', $m), "\n";
class P { public function parentM(): void {} }
class C extends P { public function childM(): void {} }
$m2 = get_class_methods(C::class);
sort($m2);
echo implode(',', $m2), "\n";
--EXPECT--
a,b
childM,parentM
