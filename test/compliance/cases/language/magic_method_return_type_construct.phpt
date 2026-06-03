--TEST--
Language: __construct(): int — compile-time fatal (#4988)
--FILE--
<?php
class C4 { public function __construct(): int { return 1; } }
--EXPECT_EXIT--
255
