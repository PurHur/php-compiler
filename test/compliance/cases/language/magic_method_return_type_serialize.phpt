--TEST--
Language: __serialize(): int — compile-time fatal (#4988)
--FILE--
<?php
class C1 { public function __serialize(): int { return 1; } }
--EXPECT_EXIT--
255
