--TEST--
Language: __clone(): int — compile-time fatal (#4988)
--FILE--
<?php
class C3 { public function __clone(): int { return 1; } }
--EXPECT_EXIT--
255
