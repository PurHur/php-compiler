<?php
// #32301: subclass statics share the declaring class's storage (zend_inheritance.c).
class A
{
    public static $x = 42;
}
class B extends A
{
}
var_dump(B::$x);
A::$x = 7;
var_dump(B::$x);
B::$x = 9;
var_dump(A::$x);
