--TEST--
Language: public private(set) compiles — dual access modifiers (#11868, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT--
1
