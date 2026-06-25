--TEST--
Language: public private(set) compile fatal — multiple access modifiers (#11656, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
$c = new C();
echo $c->x, "\n";
--EXPECT_EXIT--
255
