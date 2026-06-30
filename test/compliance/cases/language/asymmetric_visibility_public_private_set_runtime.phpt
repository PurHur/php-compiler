--TEST--
Language: public private(set) — compile fatal (#13960, Zend/zend_compile.c)
--FILE--
<?php
class B {
    public private(set) string $label = 'hi';
}
$b = new B();
echo $b->label, "\n";
--EXPECT_EXIT--
255
