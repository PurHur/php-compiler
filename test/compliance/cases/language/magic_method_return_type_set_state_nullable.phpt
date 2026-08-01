--TEST--
Language: __set_state(): ?object — compile-time fatal (#26484)
--FILE--
<?php
class C { public static function __set_state(array $a): ?object { return null; } }
--EXPECTF--
Fatal error: C::__set_state(): Return type must be object when declared in %s on line %d
--EXPECT_EXIT--
255
