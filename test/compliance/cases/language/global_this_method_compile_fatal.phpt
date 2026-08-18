--TEST--
Language: method-scope global $this is compile-time fatal (#32180, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m() {
        global $this;
        echo "accepted\n";
    }
}
(new C())->m();
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as global variable in %s on line %d
