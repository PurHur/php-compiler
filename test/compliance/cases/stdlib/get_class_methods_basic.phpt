--TEST--
Stdlib: get_class_methods() — method list for class and object (VM, #3118)
--FILE--
<?php
class C {
    public function a(): void {}
    protected function b(): void {}
    private function c(): void {}
}
$byClass = get_class_methods(C::class);
$byObject = get_class_methods(new C());
sort($byClass);
sort($byObject);
echo count($byClass), "\n";
echo in_array('a', $byClass, true) ? '1' : '0';
echo in_array('b', $byClass, true) ? '1' : '0';
echo in_array('c', $byClass, true) ? '1' : '0';
echo count($byObject), "\n";
echo in_array('a', $byObject, true) ? '1' : '0';
$publicOnly = get_class_methods(C::class, 1);
sort($publicOnly);
echo count($publicOnly), "\n";
echo $publicOnly[0] === 'a' ? '1' : '0';
echo get_class_methods('MissingClass') ? '1' : '0';
echo "\n";
--EXPECT--
3
1113
11
10
