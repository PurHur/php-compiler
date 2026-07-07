--TEST--
stdlib get_class_methods() — default filter returns public methods only (#4756, basic_functions.c)
--FILE--
<?php
class C {
    public function a(): void {}
    private function b(): void {}
    public static function c(): void {}
}
var_export(get_class_methods(C::class));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'c',
)
