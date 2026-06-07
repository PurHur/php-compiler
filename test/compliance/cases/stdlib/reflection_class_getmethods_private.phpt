--TEST--
Stdlib: ReflectionClass::getMethods() hides parent private methods on child (VM, #7191)
--FILE--
<?php
declare(strict_types=1);

class A {
    public function pubA(): void {}
    protected function protA(): void {}
    private function privA(): void {}
    public static function statA(): void {}
}
class B extends A {
    public function pubB(): void {}
    private function privB(): void {}
}

$r = new ReflectionClass(B::class);
$names = array_map(fn ($m) => $m->getName(), $r->getMethods());
sort($names);
var_export($names);
echo "\n";
--EXPECT--
array (
  0 => 'privB',
  1 => 'protA',
  2 => 'pubA',
  3 => 'pubB',
  4 => 'statA',
)
