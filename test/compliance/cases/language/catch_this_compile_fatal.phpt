--TEST--
Language: catch (Exception $this) is compile-time fatal (#32204, Zend/zend_compile.c)
--FILE--
<?php
class C {
    public function m(): void {
        try {
            throw new Exception('x');
        } catch (Exception $this) {
            echo "accepted\n";
        }
    }
}
(new C())->m();
--EXPECT_EXIT--
255
--EXPECTF--
%APHP Fatal error:  Cannot re-assign $this in %s on line %d
