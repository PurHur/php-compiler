--TEST--
class redeclares trait constant with identical value — composition succeeds (#7012, Zend/zend_traits.c)
--FILE--
<?php
trait T { public const X = 1; }
class C { use T; public const X = 1; }
echo C::X, "\n";
--EXPECT--
1
