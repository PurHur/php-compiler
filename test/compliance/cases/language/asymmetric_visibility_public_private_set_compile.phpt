--TEST--
Language: public private(set) — compiles and reads (#15368, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT_EXIT--
255
