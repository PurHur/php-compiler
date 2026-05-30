--TEST--
Language: incomplete interface implementation compile-time fatal (#3386)
--FILE--
<?php
interface I {
    public function f(): int;
}
class C implements I {}
echo "ok\n";
--EXPECT_EXIT--
255
