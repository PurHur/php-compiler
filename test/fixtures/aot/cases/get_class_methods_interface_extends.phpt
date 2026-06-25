--TEST--
AOT get_class_methods() — extended interface includes parent methods (#11689)
--FILE--
<?php
declare(strict_types=1);
interface I { public function a(): void; }
interface J extends I { public function b(): void; }
$m = get_class_methods(J::class);
sort($m);
echo implode(',', $m), "\n";
--EXPECT--
a,b
