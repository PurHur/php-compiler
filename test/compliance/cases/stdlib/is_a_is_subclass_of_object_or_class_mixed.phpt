--TEST--
stdlib is_a/is_subclass_of Reflection object_or_class mixed (#26359, Zend/zend_builtin_functions.stub.php)
--FILE--
<?php
foreach (['is_a', 'is_subclass_of'] as $f) {
    $p = (new ReflectionFunction($f))->getParameters()[0];
    echo $f, ' ', $p->getName(), ' type=', ($p->hasType() ? (string) $p->getType() : '(none)'), "\n";
}
class A26359 {}
class B26359 extends A26359 {}
echo 'named_is_a=', var_export(is_a(object_or_class: new B26359, class: 'A26359'), true), "\n";
echo 'named_subclass=', var_export(is_subclass_of(object_or_class: 'B26359', class: 'A26359'), true), "\n";
--EXPECT--
is_a object_or_class type=mixed
is_subclass_of object_or_class type=mixed
named_is_a=true
named_subclass=true
