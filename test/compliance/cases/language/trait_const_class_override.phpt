--TEST--
class overrides trait constant with incompatible value — composition fatal (#7012, Zend/zend_traits.c)
--FILE--
<?php
trait T { public const X = 1; }
class C { use T; public const X = 2; }
echo "unreachable\n";
--EXPECT_EXIT--
255
