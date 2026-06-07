--TEST--
Stdlib: get_class_methods()/method_exists() on interface and abstract class (#7398)
--FILE--
<?php
interface I {
    public function m(): void;
}
$methods = get_class_methods(I::class);
sort($methods);
echo count($methods), "\n";
echo in_array('m', $methods, true) ? '1' : '0';
echo method_exists(I::class, 'm') ? '1' : '0';

abstract class A {
    abstract public function m(): void;
}
echo method_exists(A::class, 'm') ? '1' : '0';

class_alias(I::class, 'IAlias');
echo method_exists('IAlias', 'm') ? '1' : '0';
echo "\n";
--EXPECT--
1
1111
