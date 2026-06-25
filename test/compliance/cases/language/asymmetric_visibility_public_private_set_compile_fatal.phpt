--TEST--
Language: public private(set) compile fatal — multiple access modifiers (#11656, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo "compiled\n";
--EXPECT_EXIT--
255
