--TEST--
Language: enum use Trait — missing trait abstract method compile-error (#6640, Zend/zend_compile.c)
--FILE--
<?php
trait T {
    abstract public function f(): string;
}

enum E: string {
    case A = 'a';
    use T;
}

echo "compiled\n";
--EXPECT_EXIT--
255
