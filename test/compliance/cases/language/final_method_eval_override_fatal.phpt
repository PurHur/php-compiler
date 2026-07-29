--TEST--
Language: eval() cannot override final method (#24884, Zend/zend_inheritance.c)
--FILE--
<?php
class A {
    final public function f(): void {}
}
// Guard isFinal without emitting stdout before the inherit fatal (BaseTest #7468).
if (!(new ReflectionMethod('A', 'f'))->isFinal()) {
    echo "not-final\n";
}
eval('class B extends A { public function f(): void {} }');
echo "EVAL_OK\n";
--EXPECTF--
PHP Fatal error:  Cannot override final method A::f() in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
