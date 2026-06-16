--TEST--
Incompatible trait/class constant composition — compile-time fatal (#8882, Zend/zend_traits.c)
--FILE--
<?php
trait T { public const X = 1; }
class C { use T; public const X = 2; }
echo "unreachable\n";
--EXPECT_EXIT--
255
