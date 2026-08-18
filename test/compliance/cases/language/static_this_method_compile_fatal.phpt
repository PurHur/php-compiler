--TEST--
Language: method-scope static $this is compile-time fatal (#32181, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m() {
        static $this;
        echo "accepted\n";
    }
}
(new C())->m();
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as static variable in %s on line %d
