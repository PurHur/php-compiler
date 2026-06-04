--TEST--
Language: void return null — compile-time fatal (#5367)
--FILE--
<?php
class C {
    public function f(): void {
        return null;
    }
}
(new C)->f();
echo "after\n";
--EXPECT_EXIT--
255
