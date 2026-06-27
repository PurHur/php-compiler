--TEST--
Language: class use self as trait — compile fatal Trait not found (#12868, zend_compile.c)
--FILE--
<?php
class C {
    use C {
        foo as private bar;
    }
    public function foo(): void {}
}
echo "unreached\n";
--EXPECT_EXIT--
255
--EXPECTF--
Fatal error: Trait "C" not found %A
