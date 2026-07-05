--TEST--
Language: public private(set) unparenthesized — compiles and reads (#16142, #15368, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT--
1
