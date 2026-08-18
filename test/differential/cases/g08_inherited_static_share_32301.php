<?php
// #32301: inherited static properties share declaring-class storage.
class A { public static $x = 42; }
class B extends A {}
var_dump(B::$x);
A::$x = 7;
var_dump(B::$x);
B::$x = 9;
var_dump(A::$x);
