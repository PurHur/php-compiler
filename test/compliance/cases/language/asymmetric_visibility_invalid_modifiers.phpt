--TEST--
Language: asymmetric visibility — set more permissive than read compile fatal (#7308, zend_compile.c)
--FILE--
<?php
class A {
    protected public(set) string $x = 'a';
}
echo "compiled\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class B {
    private protected(set) string $x = 'a';
}
echo "compiled\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
--FILE--
<?php
class D {
    public function __construct(public private(set) int $x = 1) {}
}
echo "compiled\n";
--EXPECT_EXIT--
255
