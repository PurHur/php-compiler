--TEST--
Stdlib: class_meth_exists() class-string method probe (JIT, #7068)
--FILE--
<?php
class Box {
    public function __construct() {}
}

class Parent_ {
    public function pub() {}
    protected function prot() {}
    private function priv() {}
}

class Child extends Parent_ {}

echo (function_exists('class_meth_exists') ? '1' : '0');
echo (class_meth_exists('Box', '__construct') ? '1' : '0');
echo (class_meth_exists('Parent_', 'pub') ? '1' : '0');
echo (class_meth_exists('Parent_', 'prot') ? '1' : '0');
echo (class_meth_exists('Parent_', 'priv') ? '1' : '0');
echo (class_meth_exists('Child', 'pub') ? '1' : '0');
echo (class_meth_exists('Child', 'priv') ? '1' : '0');
echo (class_meth_exists('Missing', 'foo') ? '1' : '0');
echo (class_meth_exists('Closure', '__invoke') ? '1' : '0');
echo "\n";
--EXPECT--
111111001
