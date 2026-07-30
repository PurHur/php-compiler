--TEST--
stdlib is_subclass_of Reflection allow_string default true (#25439, Zend/zend_builtin_functions.stub.php)
--FILE--
<?php
$p = (new ReflectionFunction('is_subclass_of'))->getParameters()[2];
echo 'name=', $p->getName(), "\n";
echo 'opt=', $p->isOptional() ? 'Y' : 'N', "\n";
echo 'default=', var_export($p->getDefaultValue(), true), "\n";

class A {}
class B extends A {}
echo 'runtime_omit=', var_export(is_subclass_of('B', 'A'), true), "\n";
echo 'named=', var_export(is_subclass_of(object_or_class: 'B', class: 'A', allow_string: true), true), "\n";

$isA = (new ReflectionFunction('is_a'))->getParameters()[2];
echo 'is_a_default=', var_export($isA->getDefaultValue(), true), "\n";
--EXPECT--
name=allow_string
opt=Y
default=true
runtime_omit=true
named=true
is_a_default=false
