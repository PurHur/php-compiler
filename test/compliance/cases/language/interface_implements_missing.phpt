--TEST--
missing interface method is rejected (issue #144)
--FILE--
<?php
interface I {
    public function m(): void;
}
class C implements I {}
echo "ok\n";
--EXPECT_EXIT--
255
