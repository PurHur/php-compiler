--TEST--
Language: unset($this) inside instance method is compile-time fatal (#5436)
--FILE--
<?php
class C {
    public function m(): void {
        unset($this);
        echo "done\n";
    }
}
(new C())->m();
--EXPECT_EXIT--
255
