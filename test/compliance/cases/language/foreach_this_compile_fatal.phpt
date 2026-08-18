--TEST--
Language: foreach as $this (value form) stays compile-time fatal (#32205, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m(): void {
        $a = [1];
        foreach ($a as $this) {
            echo "accepted\n";
        }
    }
}
(new C())->m();
--EXPECT_EXIT--
255
--EXPECTF--
%AparseAndCompile failure: target=%s: Cannot re-assign $this
