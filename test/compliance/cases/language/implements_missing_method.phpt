--TEST--
Language: implements clause — missing interface method compile-time fatal (#3536)
--FILE--
<?php
interface I {
    public function required(): int;
}
class C implements I {
}
new C();
--EXPECT_EXIT--
255
