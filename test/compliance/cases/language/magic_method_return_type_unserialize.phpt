--TEST--
Language: __unserialize(): int — compile-time fatal (#4988)
--FILE--
<?php
class C2 { public function __unserialize(array $d): int { return 1; } }
--EXPECT_EXIT--
255
