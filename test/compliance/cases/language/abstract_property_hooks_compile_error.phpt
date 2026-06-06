--TEST--
Concrete class with abstract property hook must compile-error (#6763, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public string $p {
        get;
    }
}
echo "compiled\n";
--EXPECT_EXIT--
255
