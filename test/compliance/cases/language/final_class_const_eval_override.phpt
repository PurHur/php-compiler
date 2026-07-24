--TEST--
Language: eval() cannot override final class constant (#22329, #22922, Zend/zend_inheritance.c)
--FILE--
<?php
class A {
    final public const X = 'a';
}
// Guard isFinal without emitting stdout before the compile fatal (BaseTest #7468).
if (!(new ReflectionClassConstant('A', 'X'))->isFinal()) {
    echo "not-final\n";
}
eval('class B extends A { public const X = "b"; }');
echo "allowed\n";
--EXPECTF--
PHP Fatal error:  B::X cannot override final constant A::X in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
