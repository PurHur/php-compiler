--TEST--
class cannot override final trait constant at composition (#5426, Zend/zend_traits.c)
--FILE--
<?php
trait T { final public const X = 1; }
class C { use T; public const X = 2; }
var_export(C::X);
--EXPECT_EXIT--
255
