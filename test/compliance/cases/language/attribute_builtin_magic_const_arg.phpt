--TEST--
Language: attribute ctor args fold built-in + file magic constants (#26030, zend_compile.c)
--FILE--
<?php
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class A {
    public function __construct(public mixed $v) {}
}
#[A(PHP_INT_MAX)]
#[A(E_ERROR)]
#[A(__DIR__)]
#[A(__FILE__)]
#[A(__LINE__)]
class C {}
$attrs = (new ReflectionClass(C::class))->getAttributes();
echo $attrs[0]->newInstance()->v, "\n";
echo $attrs[1]->newInstance()->v, "\n";
echo $attrs[2]->newInstance()->v === __DIR__ ? "dir-ok\n" : "dir-bad\n";
echo $attrs[3]->newInstance()->v === __FILE__ ? "file-ok\n" : "file-bad\n";
echo $attrs[4]->newInstance()->v, "\n";
--EXPECTF--
%d
1
dir-ok
file-ok
10
