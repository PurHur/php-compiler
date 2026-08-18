--TEST--
Language: method-scope closure use($this) is compile-time fatal (#32152, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m() {
        $f = function () use ($this) { return 1; };
        echo "accepted\n";
        return $f;
    }
}
(new C())->m();
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot use $this as lexical variable in %s on line %d
