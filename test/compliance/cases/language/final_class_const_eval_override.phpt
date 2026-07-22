--TEST--
Language: eval() cannot override final class constant (#22329, Zend/zend_inheritance.c)
--FILE--
<?php
class A {
    final public const X = 'a';
}
$r = new ReflectionClassConstant('A', 'X');
var_export($r->isFinal());
echo "\n";
eval('class B extends A { public const X = "b"; }');
echo "allowed\n";
--EXPECT_EXIT--
255
