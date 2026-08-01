--TEST--
Language: __set(): int — compile-time fatal (#26432)
--FILE--
<?php
class Cs { public function __set(string $n, mixed $v): int { return 1; } }
--EXPECTF--
Fatal error: Cs::__set(): Return type must be void when declared in %s on line %d
--EXPECT_EXIT--
255
