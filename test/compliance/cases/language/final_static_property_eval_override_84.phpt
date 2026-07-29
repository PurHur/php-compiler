--TEST--
Language: eval cannot override final static property under PROFILE=8.4 (#24992, Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
    public final static string $x = 'a';
}
// Guard isFinal without emitting stdout before the inherit fatal (BaseTest #7468).
if (!(new ReflectionProperty('A', 'x'))->isFinal()) {
    echo "not-final\n";
}
eval('class B extends A { public static string $x = "b"; }');
echo "EVAL_OK\n";
--EXPECTF--
PHP Fatal error:  Cannot override final property A::$x in %s : eval()'d code on line %d
--EXPECT_EXIT--
255
