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
try {
    get_class_methods('MissingClass');
    echo '0';
} catch (TypeError $e) {
    echo '1';
}
echo "\n";
--EXPECT--
1
1001
11
