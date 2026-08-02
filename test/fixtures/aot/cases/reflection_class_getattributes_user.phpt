--TEST--
AOT: ReflectionClass::getAttributes user attribute (#26828)
--FILE--
<?php
#[Attribute]
class A {}
#[A]
class B {}
$r = new ReflectionClass(B::class);
$attrs = $r->getAttributes();
echo count($attrs), ' ', $attrs[0]->getName(), "\n";
--EXPECT--
1 A
--EXPECT_EXIT--
0
