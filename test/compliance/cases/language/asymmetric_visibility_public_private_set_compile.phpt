--TEST--
Language: public private(set) unparenthesized — compiles on 8.4 profile (#16858, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT--
1
