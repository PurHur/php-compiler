--TEST--
Language: $this reassignment — compile-time fatal (#4865)
--FILE--
<?php
class C {
    public function m(): void {
        $this = new C();
        echo "reassigned\n";
    }
}
(new C())->m();
--EXPECT_EXIT--
255
