--TEST--
AOT get_class_methods() on class and object (issue #3118)
--FILE--
<?php
class Worker {
    public function run(): void {}
    private function secret(): void {}
}
$methods = get_class_methods('Worker');
sort($methods);
echo count($methods), "\n";
echo in_array('run', $methods, true) ? '1' : '0';
echo in_array('secret', $methods, true) ? '1' : '0';
$objMethods = get_class_methods(new Worker());
sort($objMethods);
echo count($objMethods), "\n";
echo in_array('run', $objMethods, true) ? '1' : '0';
--EXPECT--
2
112
1
