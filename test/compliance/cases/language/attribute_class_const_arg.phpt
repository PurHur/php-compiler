--TEST--
Language: attribute constructor arguments accept class constants (#19908, zend_attributes.c)
--FILE--
<?php
class T {
    public const X = 1;
}
#[Attribute]
class A {
    public function __construct(public int $v) {}
}
#[A(T::X)]
function f() {}
$attrs = (new ReflectionFunction('f'))->getAttributes();
var_export($attrs[0]->getArguments());
echo "\n", $attrs[0]->newInstance()->v, "\n";
--EXPECT--
array (
  0 => 1,
)
1
