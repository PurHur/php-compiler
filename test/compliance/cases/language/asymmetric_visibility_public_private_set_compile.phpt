--TEST--
Language: public private(set) compiles on 8.4 forward profile (#13914, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public private(set) int $x = 1;
}
echo (new C())->x, "\n";
--EXPECT--
1
