--TEST--
Language: enum abstract methods must compile-error (#6618, Zend/zend_compile.c)
--FILE--
<?php
enum E {
    abstract public function f(): void;
    case A;
}
echo "compiled\n";
--EXPECT_EXIT--
255
