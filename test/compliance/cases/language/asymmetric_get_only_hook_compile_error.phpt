--TEST--
Language: private(set) property with get-only hook — compile error (#12203, Zend/zend_compile.c)
--FILE--
<?php
class C {
    private(set) string $x {
        get => 'hi';
    }
}
echo "ok\n";
--EXPECT_EXIT--
255
